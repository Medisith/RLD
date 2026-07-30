-- =========================================================================
-- Leaky Bucket atômico — Fase 3 do rate limiter distribuído.
--
-- Responsabilidade: mesma garantia de atomicidade do token_bucket.lua
-- (leitura + drenagem + decisão + escrita em um único passo indivisível no
-- servidor), com semântica INVERTIDA: em vez de um saldo que recarrega, um
-- nível de "água" que só ESVAZIA a uma vazão constante.
--
-- Semântica:
--   capacity  = volume máximo do balde (quanto de trabalho pode ficar
--               represado antes de negar)
--   leak_rate = unidades drenadas por segundo (vazão constante de saída)
--   cost      = volume que esta requisição despeja no balde
--
-- Cada requisição admitida ADICIONA cost ao nível; o nível desce sozinho a
-- leak_rate por segundo. Resultado: o downstream nunca recebe mais que
-- leak_rate por segundo em regime, e o excedente de burst espera (429 com
-- retry) em vez de passar de uma vez — contraste direto com o Token Bucket,
-- que gasta o burst imediatamente. Comparativo em docs/fases/fase-3.
--
-- Estado por chave (HASH):
--   level         -> nível atual do balde (float serializado como string)
--   last_leak_ms  -> instante da última drenagem aplicada, em milissegundos
--
-- Relógio: TIME do Redis, pela mesma justificativa do token_bucket.lua
-- (imunidade a clock skew entre instâncias PHP; chamada antes das escritas).
--
-- Entrada:
--   KEYS[1] = chave do balde (rate-limit:{strategy}:{identifier}:{routeName})
--   ARGV[1] = capacity (int > 0)
--   ARGV[2] = leak_rate (float > 0, unidades/segundo)
--   ARGV[3] = cost (int > 0)
--
-- Saída (array de 4 inteiros, floors/ceils explícitos):
--   [1] allowed      -> 1 permitido, 0 negado
--   [2] remaining    -> floor(capacidade livre restante) para X-RateLimit-Remaining
--   [3] retry_after  -> 0 se permitido; senão ceil(segundos até drenar o
--                       suficiente para "cost" caber)
--   [4] reset_after  -> ceil(segundos até o balde drenar por COMPLETO), para
--                       X-RateLimit-Reset (Fase 4). Sempre >= retry_after:
--                       retry diz quando UMA requisição volta a caber;
--                       reset diz quando o estado volta ao repouso total.
-- =========================================================================

local bucket_key = KEYS[1]
local capacity = tonumber(ARGV[1])
local leak_rate = tonumber(ARGV[2])
local cost = tonumber(ARGV[3])

local redis_time = redis.call('TIME')
local now_ms = (tonumber(redis_time[1]) * 1000) + math.floor(tonumber(redis_time[2]) / 1000)

-- Chave ausente = balde VAZIO: nada represado para este cliente.
local state = redis.call('HMGET', bucket_key, 'level', 'last_leak_ms')
local level = tonumber(state[1])
local last_leak_ms = tonumber(state[2])

if level == nil or last_leak_ms == nil then
    level = 0
    last_leak_ms = now_ms
end

-- Drenagem proporcional ao tempo decorrido, saturada em zero (balde nunca
-- fica "negativo"). max(0, elapsed) protege contra retrocesso de relógio.
local elapsed_ms = math.max(0, now_ms - last_leak_ms)
level = math.max(0, level - (elapsed_ms / 1000) * leak_rate)

local allowed = 0
local retry_after = 0

if level + cost <= capacity then
    -- Admitido: o volume desta requisição entra no balde. Comparação e
    -- escrita são atômicas do ponto de vista de qualquer outro cliente.
    level = level + cost
    allowed = 1
else
    -- Negado: instrui quanto tempo de drenagem falta para "cost" caber.
    local overflow = (level + cost) - capacity
    retry_after = math.ceil(overflow / leak_rate)
end

redis.call('HSET', bucket_key, 'level', tostring(level), 'last_leak_ms', now_ms)

-- TTL de higiene: a chave só precisa viver até o balde drenar por completo
-- (balde vazio é indistinguível de chave ausente). +1s de margem.
-- O MESMO cálculo alimenta o reset_after do header X-RateLimit-Reset:
-- uma única definição de "voltar ao repouso" para TTL e para o cliente.
local seconds_until_empty = math.max(0, math.ceil(level / leak_rate))
redis.call('EXPIRE', bucket_key, math.max(1, seconds_until_empty + 1))

return { allowed, math.floor(capacity - level), retry_after, seconds_until_empty }
