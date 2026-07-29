<?php

declare(strict_types=1);

/**
 * Prova empírica da race condition do LimitadorIngenuoRedis (Fase 1).
 *
 * Responsabilidade: disparar MUITAS tentativas de consumo CONCORRENTES
 * contra uma única chave de limitação e comparar "permitidos esperados"
 * (a capacidade da política) com "permitidos obtidos". Todo excesso obtido
 * acima da capacidade é contagem perdida pelo check-then-act não atômico.
 *
 * Dois modos de execução:
 *
 *   --modo=algoritmo (padrão)
 *       Fork de N processos PHP (ext-pcntl); cada um instancia o MESMO
 *       LimitadorIngenuoRedis usado pelo middleware, porém sobre o
 *       adaptador phpredis puro (ClienteRedisNativo) — não requer vendor/
 *       nem aplicação de pé. Uma barreira de largada via chave no Redis
 *       garante que todos os processos batam no algoritmo ao mesmo tempo.
 *
 *   --modo=http --url=http://localhost:8000/api/limitado/ping
 *       Requisições HTTP reais e concorrentes (curl_multi) contra a rota
 *       protegida pelo middleware. Requer a aplicação instalada e servida
 *       (php artisan serve) e mede o mesmo fenômeno fim a fim.
 *
 * Uso típico (documentado em docs/fases/fase-1-race-condition.md):
 *   php scripts/provar_race_condition.php --processos=40 --tentativas=5 --rodadas=3
 *
 * O script NUNCA inventa números: sem Redis (ou sem aplicação no modo http)
 * ele falha de forma explícita e imediata.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$raizProjeto = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Autoloader mínimo: mapeia App\ -> app/ para rodar o domínio SEM vendor/.
// Somente classes puras (algoritmo, DTOs, contratos, adaptador nativo) são
// carregadas neste script — nada de Illuminate aqui.
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $classe) use ($raizProjeto): void {
    if (! str_starts_with($classe, 'App\\')) {
        return;
    }

    $caminho = $raizProjeto.'/app/'.str_replace('\\', '/', substr($classe, 4)).'.php';

    if (is_file($caminho)) {
        require $caminho;
    }
});

use App\LimitacaoRequisicoes\Algoritmos\LimitadorIngenuoRedis;
use App\LimitacaoRequisicoes\Excecoes\ExcecaoRedisIndisponivel;
use App\LimitacaoRequisicoes\Infraestrutura\ClienteRedisNativo;
use App\LimitacaoRequisicoes\Suporte\AlgoritmoDisponivel;
use App\LimitacaoRequisicoes\Suporte\EstrategiaChave;
use App\LimitacaoRequisicoes\Suporte\PoliticaLimitacao;

// ---------------------------------------------------------------------------
// Leitura de opções de linha de comando (tudo com padrão sensato).
// ---------------------------------------------------------------------------
$opcoes = getopt('', [
    'modo::', 'processos::', 'tentativas::', 'capacidade::', 'janela::',
    'custo::', 'rodadas::', 'redis-host::', 'redis-porta::', 'redis-banco::',
    'url::', 'ajuda',
]);

if (isset($opcoes['ajuda'])) {
    echo <<<AJUDA
    Uso: php scripts/provar_race_condition.php [opcoes]
      --modo=algoritmo|http   (padrão: algoritmo)
      --processos=N           processos concorrentes no modo algoritmo /
                              conexões simultâneas no modo http (padrão: 40)
      --tentativas=N          tentativas por processo (padrão: 5)
      --capacidade=N          capacidade da política (padrão: 50)
      --janela=N              janela/TTL em segundos (padrão: 60)
      --custo=N               custo por tentativa (padrão: 1)
      --rodadas=N             repetições do experimento (padrão: 3)
      --redis-host=HOST       (padrão: 127.0.0.1)   [modo algoritmo]
      --redis-porta=PORTA     (padrão: 6379)        [modo algoritmo]
      --redis-banco=N         (padrão: 0)           [modo algoritmo]
      --url=URL               rota protegida         [modo http]

    AJUDA;
    exit(0);
}

$modo = (string) ($opcoes['modo'] ?? 'algoritmo');
$quantidadeProcessos = max(2, (int) ($opcoes['processos'] ?? 40));
$tentativasPorProcesso = max(1, (int) ($opcoes['tentativas'] ?? 5));
$capacidade = max(1, (int) ($opcoes['capacidade'] ?? 50));
$janelaSegundos = max(1, (int) ($opcoes['janela'] ?? 60));
$custo = max(1, (int) ($opcoes['custo'] ?? 1));
$rodadas = max(1, (int) ($opcoes['rodadas'] ?? 3));
$redisHost = (string) ($opcoes['redis-host'] ?? '127.0.0.1');
$redisPorta = (int) ($opcoes['redis-porta'] ?? 6379);
$redisBanco = (int) ($opcoes['redis-banco'] ?? 0);
$urlAlvo = (string) ($opcoes['url'] ?? 'http://localhost:8000/api/limitado/ping');

$totalTentativas = $quantidadeProcessos * $tentativasPorProcesso;
$permitidosEsperados = min($capacidade, $totalTentativas);

echo "=== Prova de race condition — LimitadorIngenuoRedis (Fase 1) ===\n";
echo "modo={$modo} | processos={$quantidadeProcessos} | tentativas/processo={$tentativasPorProcesso} | ";
echo "total de tentativas={$totalTentativas}\n";
echo "política: capacidade={$capacidade}, janela={$janelaSegundos}s, custo={$custo}\n";
echo "permitidos esperados por rodada (o correto): {$permitidosEsperados}\n\n";

/**
 * Recebe: linhas de resultado por rodada. Faz: imprime a tabela em Markdown
 * (pronta para colar em docs/fases/fase-1-race-condition.md) e o veredito.
 * Retorna: void. Efeitos colaterais: escrita em stdout.
 *
 * @param list<array{rodada: int, esperado: int, obtido: int}> $linhas
 */
function imprimirRelatorio(array $linhas): void
{
    echo "\n| Rodada | Permitidos esperados | Permitidos obtidos | Excesso admitido |\n";
    echo "|-------:|---------------------:|-------------------:|-----------------:|\n";

    $houveExcesso = false;

    foreach ($linhas as $linha) {
        $excesso = $linha['obtido'] - $linha['esperado'];
        $houveExcesso = $houveExcesso || $excesso > 0;
        $percentual = $linha['esperado'] > 0
            ? sprintf('%+d (%+.0f%%)', $excesso, 100 * $excesso / $linha['esperado'])
            : (string) $excesso;
        echo sprintf(
            "| %6d | %20d | %18d | %16s |\n",
            $linha['rodada'],
            $linha['esperado'],
            $linha['obtido'],
            $percentual,
        );
    }

    echo "\nVeredito: ";
    echo $houveExcesso
        ? "RACE CONDITION DEMONSTRADA — o limitador ingênuo admitiu mais consumos do que a capacidade.\n"
        : "excesso não observado NESTA execução (concorrência é probabilística — aumente --processos/--tentativas e repita).\n";
}

// ===========================================================================
// MODO ALGORITMO — processos paralelos direto no LimitadorIngenuoRedis.
// ===========================================================================
if ($modo === 'algoritmo') {
    foreach (['redis', 'pcntl'] as $extensao) {
        if (! extension_loaded($extensao)) {
            fwrite(STDERR, "ERRO: extensão PHP '{$extensao}' ausente — necessária no modo algoritmo.\n");
            exit(1);
        }
    }

    // Falha explícita e imediata se o Redis não estiver de pé.
    try {
        $clienteControle = new ClienteRedisNativo($redisHost, $redisPorta, null, $redisBanco);
    } catch (ExcecaoRedisIndisponivel $falha) {
        fwrite(STDERR, "ERRO: {$falha->getMessage()}\n");
        fwrite(STDERR, "Suba um Redis local (ex.: redis-server --daemonize yes) e rode novamente.\n");
        fwrite(STDERR, "Resultado da prova: PENDENTE DE EXECUÇÃO — nenhum número foi inventado.\n");
        exit(1);
    }

    $politica = new PoliticaLimitacao(
        nome: 'prova_race',
        capacidade: $capacidade,
        janelaSegundos: $janelaSegundos,
        custoPadrao: $custo,
        estrategiaChave: EstrategiaChave::Ip,
        algoritmo: AlgoritmoDisponivel::Ingenuo,
    );

    // Chave única de contagem: TODOS os processos disputam o mesmo saldo,
    // como fariam N workers atendendo o mesmo cliente.
    $chaveAlvo = 'limitacao:ip:prova-race:prova_race';
    $chaveLargada = 'limitacao:prova:largada';

    $diretorioResultados = sys_get_temp_dir().'/prova_race_'.getmypid();
    if (! is_dir($diretorioResultados) && ! mkdir($diretorioResultados, 0777, true)) {
        fwrite(STDERR, "ERRO: não foi possível criar {$diretorioResultados}.\n");
        exit(1);
    }

    $linhasRelatorio = [];

    for ($rodada = 1; $rodada <= $rodadas; $rodada++) {
        // Estado zerado a cada rodada: contador e barreira removidos.
        $clienteControle->remover($chaveAlvo);
        $clienteControle->remover($chaveLargada);
        array_map(unlink(...), glob($diretorioResultados.'/*.resultado') ?: []);

        $pidsFilhos = [];

        for ($indice = 0; $indice < $quantidadeProcessos; $indice++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                fwrite(STDERR, "ERRO: pcntl_fork falhou no processo {$indice}.\n");
                exit(1);
            }

            if ($pid === 0) {
                // -------------------- PROCESSO FILHO --------------------
                // Conexão própria (jamais herdar o socket do pai) e o MESMO
                // algoritmo ingênuo usado pelo middleware em produção.
                try {
                    $clienteFilho = new ClienteRedisNativo($redisHost, $redisPorta, null, $redisBanco);
                    $limitador = new LimitadorIngenuoRedis($clienteFilho);

                    // Barreira de largada: espera ocupada até o pai autorizar,
                    // maximizando a sobreposição GET/INCR entre os filhos.
                    $limiteEspera = microtime(true) + 10.0;
                    while ($clienteFilho->obterValor($chaveLargada) === null) {
                        if (microtime(true) > $limiteEspera) {
                            exit(2); // largada nunca veio — aborta sem poluir a medição
                        }
                        usleep(200);
                    }

                    $permitidosNoFilho = 0;
                    for ($tentativa = 0; $tentativa < $tentativasPorProcesso; $tentativa++) {
                        $resultado = $limitador->tentar($chaveAlvo, $politica, $custo);
                        if ($resultado->permitido) {
                            $permitidosNoFilho++;
                        }
                    }

                    file_put_contents(
                        $diretorioResultados.'/'.getmypid().'.resultado',
                        (string) $permitidosNoFilho,
                    );
                    exit(0);
                } catch (Throwable $falhaFilho) {
                    fwrite(STDERR, "filho ".getmypid().": {$falhaFilho->getMessage()}\n");
                    exit(3);
                }
                // ------------------ FIM PROCESSO FILHO ------------------
            }

            $pidsFilhos[] = $pid;
        }

        // Pequena folga para todos os filhos conectarem e ficarem na barreira,
        // então: largada.
        usleep(300_000);
        $clienteControle->definirValorComTtl($chaveLargada, 1, 30);

        foreach ($pidsFilhos as $pidFilho) {
            pcntl_waitpid($pidFilho, $situacao);
        }

        // Agregação por arquivos (um por filho): a métrica não passa pelo
        // Redis para não interferir no fenômeno que está sendo medido.
        $permitidosObtidos = 0;
        foreach (glob($diretorioResultados.'/*.resultado') ?: [] as $arquivoResultado) {
            $permitidosObtidos += (int) file_get_contents($arquivoResultado);
        }

        $valorFinalContador = $clienteControle->obterValor($chaveAlvo);

        echo sprintf(
            "rodada %d: esperados=%d, obtidos=%d, contador final no Redis=%s\n",
            $rodada,
            $permitidosEsperados,
            $permitidosObtidos,
            $valorFinalContador ?? '(chave ausente)',
        );

        $linhasRelatorio[] = ['rodada' => $rodada, 'esperado' => $permitidosEsperados, 'obtido' => $permitidosObtidos];
    }

    // Limpeza final: nada de lixo no Redis depois da prova.
    $clienteControle->remover($chaveAlvo);
    $clienteControle->remover($chaveLargada);

    imprimirRelatorio($linhasRelatorio);
    exit(0);
}

// ===========================================================================
// MODO HTTP — requisições concorrentes reais contra a rota protegida.
// ===========================================================================
if ($modo === 'http') {
    if (! extension_loaded('curl')) {
        fwrite(STDERR, "ERRO: extensão PHP 'curl' ausente — necessária no modo http.\n");
        exit(1);
    }

    $linhasRelatorio = [];

    for ($rodada = 1; $rodada <= $rodadas; $rodada++) {
        // No modo http o estado fica no Redis da APLICAÇÃO; para isolar as
        // rodadas o operador deve limpar a chave (FLUSHDB do banco usado ou
        // DEL da chave) OU aguardar a janela expirar. Documentado na fase 1.
        $multi = curl_multi_init();
        $identificadores = [];

        for ($indice = 0; $indice < $totalTentativas; $indice++) {
            $requisicao = curl_init($urlAlvo);
            curl_setopt_array($requisicao, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            curl_multi_add_handle($multi, $requisicao);
            $identificadores[] = $requisicao;
        }

        // Dispara tudo de uma vez e drena até terminar.
        do {
            $codigoExecucao = curl_multi_exec($multi, $emAndamento);
            if ($emAndamento > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($emAndamento > 0 && $codigoExecucao === CURLM_OK);

        $permitidosObtidos = 0;
        $negados = 0;
        $falhasTransporte = 0;

        foreach ($identificadores as $requisicao) {
            $status = (int) curl_getinfo($requisicao, CURLINFO_RESPONSE_CODE);
            match (true) {
                $status === 200 => $permitidosObtidos++,
                $status === 429 => $negados++,
                default => $falhasTransporte++,
            };
            curl_multi_remove_handle($multi, $requisicao);
            curl_close($requisicao);
        }

        curl_multi_close($multi);

        if ($falhasTransporte === $totalTentativas) {
            fwrite(STDERR, "ERRO: nenhuma resposta HTTP de {$urlAlvo} — a aplicação está de pé?\n");
            fwrite(STDERR, "Resultado da prova: PENDENTE DE EXECUÇÃO — nenhum número foi inventado.\n");
            exit(1);
        }

        echo sprintf(
            "rodada %d: esperados=%d, obtidos(200)=%d, negados(429)=%d, falhas de transporte=%d\n",
            $rodada,
            $permitidosEsperados,
            $permitidosObtidos,
            $negados,
            $falhasTransporte,
        );

        $linhasRelatorio[] = ['rodada' => $rodada, 'esperado' => $permitidosEsperados, 'obtido' => $permitidosObtidos];

        if ($rodada < $rodadas) {
            echo "aguardando a janela expirar para a próxima rodada...\n";
            sleep($janelaSegundos + 1);
        }
    }

    imprimirRelatorio($linhasRelatorio);
    exit(0);
}

fwrite(STDERR, "ERRO: modo desconhecido '{$modo}' (use --modo=algoritmo ou --modo=http).\n");
exit(1);
