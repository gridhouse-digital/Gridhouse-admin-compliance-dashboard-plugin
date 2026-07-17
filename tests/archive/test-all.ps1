$ErrorActionPreference = "Stop"
$env:GHCA_TEST_QUIET = "1"

$php_versions = @(
    "$env:TEMP\ghca-php-7.4.33-nts-x64\php.exe",
    "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe",
    "C:\laragon\bin\php\php-8.5.7-nts-Win32-vs17-x64\php.exe"
)

$db_ports = @(
    33061, # MySQL 8.0
    33062, # MySQL 8.4
    33063  # MariaDB 10.6
)

$required_vars = @(
    "GHCA_TEST_DB_USER",
    "GHCA_TEST_DB_PASSWORD",
    "GHCA_TEST_DB_NAME",
    "GHCA_TEST_DESTRUCTIVE_OPT_IN",
    "GHCA_TEST_RESTRICTED_DB_USER",
    "GHCA_TEST_RESTRICTED_DB_PASSWORD"
)
foreach ($var in $required_vars) {
    if (-not (Test-Path "env:\$var")) {
        Write-Error "Missing required environment variable: $var"
        exit 1
    }
}
$results = @()
foreach ($php in $php_versions) {
    if (-not (Test-Path -LiteralPath $php)) {
        Write-Error "Missing required PHP runtime: $php"
        exit 1
    }
    foreach ($port in $db_ports) {
        Write-Host "========================================"
        Write-Host "Testing PHP: $php on DB Port: $port"
        Write-Host "========================================"
        $env:GHCA_TEST_DB_HOST = "127.0.0.1:$port"

        $suites = @(
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-schema-migration.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-persistence.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-side-record-persistence.php"
        )
        foreach ($suite in $suites) {
            if ($php -match "7\.4") {
                & $php -d extension_dir="ext" -d extension="mysqli" $suite
            } else {
                & $php $suite
            }

            if ($LASTEXITCODE -ne 0) {
                Write-Error "Test failed on PHP $php, DB Port $port, Suite $suite"
                exit $LASTEXITCODE
            }
        }

        $results += [PSCustomObject]@{
            PHP = (& $php -r "echo PHP_VERSION;")
            Port = $port
            ExitCode = $LASTEXITCODE
        }
    }
}

$results | Format-Table -AutoSize
Write-Host "ALL $($results.Count) SCHEMA/PERSISTENCE/SIDE-RECORD MATRIX CELLS PASSED"
