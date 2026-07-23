$ErrorActionPreference = "Stop"
$env:GHCA_TEST_QUIET = "1"

$php_versions = @(
    [PSCustomObject]@{ Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe"; Version = "8.3.30" },
    [PSCustomObject]@{ Path = "C:\laragon\bin\php\php-8.5.7-nts-Win32-vs17-x64\php.exe"; Version = "8.5.7" }
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
foreach ($runtime in $php_versions) {
    if (-not (Test-Path -LiteralPath $runtime.Path)) {
        Write-Error "Missing required PHP runtime: $($runtime.Path)"
        exit 1
    }
    $actual_version = & $runtime.Path -r "echo PHP_VERSION;"
    if ($LASTEXITCODE -ne 0 -or $actual_version -ne $runtime.Version) {
        Write-Error "Required PHP runtime mismatch: expected $($runtime.Version) at $($runtime.Path), got $actual_version"
        exit 1
    }
    if (-not ((& $runtime.Path -m) -contains "mysqli")) {
        Write-Error "Required PHP runtime cannot load mysqli: $($runtime.Path)"
        exit 1
    }
    foreach ($port in $db_ports) {
        $php = $runtime.Path
        Write-Host "========================================"
        Write-Host "Testing PHP: $php on DB Port: $port"
        Write-Host "========================================"
        $env:GHCA_TEST_DB_HOST = "127.0.0.1:$port"

        $suites = @(
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-schema-migration.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-persistence.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-side-record-persistence.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-p3-digests.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-p3-worker.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-p3-storage.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-p3b-task-contracts.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-p3b-build-coordinator.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-p3b-ledger-handler.php",
            "c:\laragon\www\Gridhouse-Healthcare-Academy\wp-content\plugins\gridhouse-admin-compliance-dashboard\tests\archive\test-p3b-ledger-failures.php"
        )
        foreach ($suite in $suites) {
            & $php $suite

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
Write-Host "ALL $($results.Count) P1/P2/P3A/P3B1 MATRIX CELLS PASSED"
