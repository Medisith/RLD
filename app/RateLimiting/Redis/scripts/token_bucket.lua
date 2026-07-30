-- =========================================================================
-- Token Bucket atômico — Fase 2 do rate limiter distribuído.
--
-- Responsabilidade: executar leitura + recarga + decisão + escrita como UMA
-- operação indivisível dentro do Redis. É exatamente o conjunto que o
-- NaiveRedisRateLimiter fazia em comandos separados (o buraco provado na
-- Fase 1); aqui nenhum outro cliente consegue observar ou intercalar estado
-- entre os passos, porque o Redis executa scripts de forma atômica.
--
-- Semântica:
--   capacity    = tamanho máximo do balde (burst máximo admitido de uma vez)
--   refill_rate = tokens recarregados por segundo (vazão média sustentada)
--   cost        = tokens consumidos por esta requisição
--
-- Estado por chave (HASH):
--   tokens          -> saldo atual (float serializado como string)
--   last_refill_ms  -> instante da última recarga, em milissegundos
--
-- Relógio: usamos TIME do PRÓPRIO Redis (não o relógio do PHP) para que N
-- instâncias de aplicação com relógios divergentes (clock skew) enxerguem a
-- mesma linha do tempo — o Redis é o único ponto de verdade também para o
-- tempo. TIME dentro de script é seguro no Redis >= 5 (replicação por
-- efeitos). A chamada acontece ANTES de qualquer escrita, como exigido.
--
-- Entrada:
--   KEYS[1] = chave do balde (rate-limit:{strategy}:{identifier}:{routeName})
--   ARGV[1] = capacity (int > 0)
--   ARGV[2] = refill_rate (float > 0, tokens/segundo)
--   ARGV[3] = cost (int > 0)
--
-- Saída (array de 3 inteiros — Lua->Redis trunca floats, então os floors e
-- ceils são EXPLÍCITOS aqui, nunca implícitos):
--   [1] allowed      -> 1 permitido, 0 negado
--   [2] remaining    -> floor(tokens restantes) para X-RateLimit-Remaining
--   [3] retry_after  -> 0 se permitido; senão ceil(segundos até acumular
--                       tokens suficientes para "cost")
-- =========================================================================

local bucket_key = KEYS[1]
local capacity = tonumber(ARGV[1])
local refill_rate = tonumber(ARGV[2])
local cost = tonumber(ARGV[3])

-- Relógio do servidor Redis em milissegundos (TIME devolve {segundos, microssegundos}).
local redis_time = redis.call('TIME')
local now_ms = (tonumber(redis_time[1]) * 1000) + math.floor(tonumber(redis_time[2]) / 1000)

-- Leitura do estado atual do balde. Chave ausente = balde CHEIO: cliente
-- novo (ou estado expirado) começa com o burst inteiro disponível.
local state = redis.call('HMGET', bucket_key, 'tokens', 'last_refill_ms')
local tokens = tonumber(state[1])
local last_refill_ms = tonumber(state[2])

if tokens == nil or last_refill_ms == nil then
    tokens = capacity
    last_refill_ms = now_ms
end

-- Recarga proporcional ao tempo decorrido, saturada na capacidade.
-- max(0, ...) protege contra retrocesso de relógio entre nós do Redis em
-- failover (raro, mas real): nunca recarrega "para trás".
local elapsed_ms = math.max(0, now_ms - last_refill_ms)
tokens = math.min(capacity, tokens + (elapsed_ms / 1000) * refill_rate)

local allowed = 0
local retry_after = 0

if tokens >= cost then
    -- Dentro do script não há corrida possível: este decremento e a
    -- comparação acima são um único passo atômico do ponto de vista de
    -- qualquer outro cliente.
    tokens = tokens - cost
    allowed = 1
else
    -- Tempo até o deficit ser recarregado — instrução honesta de retry.
    local deficit = cost - tokens
    retry_after = math.ceil(deficit / refill_rate)
end

redis.call('HSET', bucket_key, 'tokens', tostring(tokens), 'last_refill_ms', now_ms)

-- TTL de higiene: a chave só precisa viver até o balde estar cheio de novo
-- (balde cheio é indistinguível de chave ausente). +1s de margem contra
-- arredondamento. Evita chaves eternas para clientes que nunca voltam.
local seconds_until_full = math.ceil((capacity - tokens) / refill_rate)
redis.call('EXPIRE', bucket_key, math.max(1, seconds_until_full + 1))

return { allowed, math.floor(tokens), retry_after }
