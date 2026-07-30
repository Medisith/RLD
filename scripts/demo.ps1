# =========================================================================
# Demo reproduzível do rate limiter (Fase 5) — Windows / PowerShell.
#
# Responsabilidade: mesma demo do scripts/demo.sh. Limitação honesta do
# Windows nativo: o modo "algorithm" da prova forka processos via ext-pcntl,
# que NÃO existe no PHP para Windows. Estratégia deste script:
#   1) se houver WSL disponível, delega para scripts/demo.sh dentro do WSL
#      (caminho recomendado — é a demo completa);
#   2) sem WSL, explica as alternativas em vez de fingir que rodou.
# Nenhum número é fabricado.
#
# Uso: powershell -ExecutionPolicy Bypass -File scripts\demo.ps1
# =========================================================================
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $projectRoot

# ---- caminho recomendado: WSL --------------------------------------------
$wsl = Get-Command wsl.exe -ErrorAction SilentlyContinue
if ($wsl) {
    Write-Host "[demo] WSL encontrado - executando a demo completa (scripts/demo.sh) dentro do WSL..."
    wsl.exe bash -lc "cd '$($projectRoot -replace '\\','/' -replace '^([A-Za-z]):','/mnt/$1'.ToLower())' && ./scripts/demo.sh"
    exit $LASTEXITCODE
}

# ---- sem WSL: verificar o que e possivel nativamente ---------------------
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-Error "php nao encontrado no PATH."
    exit 1
}

$modules = & php -m
if ($modules -notcontains 'pcntl') {
    Write-Host ""
    Write-Host "A prova de concorrencia (modo algorithm) exige a extensao 'pcntl',"
    Write-Host "que nao existe no PHP para Windows nativo. Alternativas:"
    Write-Host ""
    Write-Host "  1. Instale o WSL (wsl --install) e rode este script de novo - ele"
    Write-Host "     delega automaticamente para scripts/demo.sh (demo completa)."
    Write-Host ""
    Write-Host "  2. Rode a variante HTTP da prova, que nao usa pcntl (exige a app"
    Write-Host "     instalada e servida - composer install; php artisan serve):"
    Write-Host "       php scripts/prove_race_condition.php --mode=http ``"
    Write-Host "           --url=http://localhost:8000/api/rate-limited/ping"
    Write-Host ""
    Write-Host "  3. Suba o Redis com: docker compose up -d"
    Write-Host ""
    Write-Host "Nenhum numero foi exibido porque nada foi executado - sem fingir demo."
    exit 1
}

# pcntl presente (PHP em ambiente tipo Cygwin/MSYS): roda a demo via bash se
# existir, senao instrui.
$bash = Get-Command bash -ErrorAction SilentlyContinue
if ($bash) {
    & bash ./scripts/demo.sh
    exit $LASTEXITCODE
}

Write-Host "Ambiente incomum (pcntl presente, bash ausente). Rode as tres provas manualmente:"
Write-Host "  php scripts/prove_race_condition.php --algorithm=naive --rounds=2"
Write-Host "  php scripts/prove_race_condition.php --algorithm=token_bucket --refill-rate=1 --rounds=2"
Write-Host "  php scripts/prove_race_condition.php --algorithm=leaky_bucket --leak-rate=1 --rounds=2"
exit 1
