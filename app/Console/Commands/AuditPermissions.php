<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class AuditPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:permissions {--fix : Automatically fix insecure permissions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit filesystem permissions on sensitive files and directories and optionally fix them';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $shouldFix = (bool) $this->option('fix');
        $this->info("Auditing sensitive files and directories for permission drift...");

        $targets = $this->gatherTargets();
        $insecure = [];
        $fixed = [];
        $failed = [];

        foreach ($targets as $target) {
            $path = $target['path'];
            $targetMode = $target['mode'];
            $checkType = $target['check'];

            if (!file_exists($path)) {
                continue;
            }

            $currentMode = @fileperms($path);
            if ($currentMode === false) {
                continue;
            }
            $currentMode = $currentMode & 0777;

            if ($this->isInsecure($currentMode, $checkType)) {
                $displayPath = $this->formatPath($path);
                $insecureItem = [
                    'path' => $displayPath,
                    'full_path' => $path,
                    'current_mode' => sprintf('%04o', $currentMode),
                    'target_mode' => sprintf('%04o', $targetMode),
                ];
                $insecure[] = $insecureItem;

                if ($shouldFix) {
                    if (@chmod($path, $targetMode)) {
                        clearstatcache(true, $path);
                        $newMode = fileperms($path) & 0777;
                        if (!$this->isInsecure($newMode, $checkType)) {
                            $fixed[] = [
                                'path' => $displayPath,
                                'old_mode' => sprintf('%04o', $currentMode),
                                'new_mode' => sprintf('%04o', $newMode),
                            ];
                            continue;
                        }
                    }

                    $failed[] = [
                        'path' => $displayPath,
                        'current_mode' => sprintf('%04o', $currentMode),
                        'target_mode' => sprintf('%04o', $targetMode),
                    ];
                }
            }
        }

        if (empty($insecure)) {
            $this->info("All audited sensitive file and directory permissions are secure.");
            return Command::SUCCESS;
        }

        $this->table(
            ['Path', 'Current Mode', 'Target Mode', 'Status'],
            collect($insecure)->map(function ($item) use ($shouldFix, $fixed) {
                $status = 'Insecure';
                if ($shouldFix) {
                    $isFixed = collect($fixed)->contains('path', $item['path']);
                    $status = $isFixed ? 'Fixed' : 'Failed to fix';
                }
                return [
                    $item['path'],
                    $item['current_mode'],
                    $item['target_mode'],
                    $status,
                ];
            })
        );

        if ($shouldFix) {
            if (!empty($fixed)) {
                AuditLog::log(
                    'security_permissions_fix',
                    "Corrected file permissions on " . count($fixed) . " sensitive path(s)",
                    [
                        'fixed_count' => count($fixed),
                        'items' => array_slice($fixed, 0, 100),
                    ]
                );

                $this->info("Successfully fixed permissions on " . count($fixed) . " path(s) and logged to audit trail.");
            }

            if (!empty($failed)) {
                $this->error("Failed to fix permissions on " . count($failed) . " path(s). Please check user ownership.");
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $this->warn("Found " . count($insecure) . " path(s) with insecure permissions. Run with '--fix' to automatically resolve.");
        return Command::FAILURE;
    }

    /**
     * Gather sensitive targets to inspect.
     *
     * @return array<int, array{path: string, mode: int, check: string}>
     */
    protected function gatherTargets(): array
    {
        $targets = [];

        // 1. Environment file (.env)
        if (file_exists(base_path('.env'))) {
            $targets[] = [
                'path' => base_path('.env'),
                'mode' => 0600,
                'check' => 'strict_private',
            ];
        }

        // 2. Database path & directory
        $dbPath = config('database.connections.sqlite.database', database_path('database.sqlite'));
        if ($dbPath === ':memory:') {
            $dbPath = database_path('database.sqlite');
        }
        $dbDir = dirname($dbPath);

        if (is_dir($dbDir)) {
            $targets[] = [
                'path' => $dbDir,
                'mode' => 0750,
                'check' => 'restricted_dir',
            ];
        }

        if (file_exists($dbPath)) {
            $targets[] = [
                'path' => $dbPath,
                'mode' => 0660,
                'check' => 'restricted_db',
            ];
        }

        // 3. Application encryption key file (.app_key)
        foreach (["$dbDir/.app_key", base_path('.app_key'), database_path('.app_key')] as $appKeyFile) {
            if (file_exists($appKeyFile)) {
                $targets[] = [
                    'path' => $appKeyFile,
                    'mode' => 0600,
                    'check' => 'strict_private',
                ];
            }
        }

        // 4. Private storage root and contents
        $privateStorage = storage_path('app/private');
        if (is_dir($privateStorage)) {
            $targets[] = [
                'path' => $privateStorage,
                'mode' => 0700,
                'check' => 'strict_private_dir',
            ];

            try {
                $finder = (new Finder())->in($privateStorage)->ignoreDotFiles(false);
                foreach ($finder as $item) {
                    $targets[] = [
                        'path' => $item->getRealPath(),
                        'mode' => $item->isDir() ? 0700 : 0600,
                        'check' => $item->isDir() ? 'strict_private_dir' : 'strict_private',
                    ];
                }
            } catch (\Throwable) {
                // Ignore unreadable paths
            }
        }

        // 5. Sensitive keys and certificates in storage/app
        if (is_dir(storage_path('app'))) {
            try {
                $keyFinder = (new Finder())
                    ->in(storage_path('app'))
                    ->files()
                    ->name('*.key')
                    ->name('*.pem')
                    ->name('*.pfx')
                    ->name('*.p12');

                foreach ($keyFinder as $file) {
                    $targets[] = [
                        'path' => $file->getRealPath(),
                        'mode' => 0600,
                        'check' => 'strict_private',
                    ];
                }
            } catch (\Throwable) {
                // Ignore unreadable paths
            }
        }

        // 6. Base storage directories (must not be world-writable)
        foreach ([storage_path(), storage_path('logs'), storage_path('framework')] as $dir) {
            if (is_dir($dir)) {
                $targets[] = [
                    'path' => $dir,
                    'mode' => 0755,
                    'check' => 'no_world_write',
                ];
            }
        }

        return collect($targets)->unique('path')->values()->all();
    }

    /**
     * Determine if a mode is insecure based on the check type.
     */
    protected function isInsecure(int $currentMode, string $checkType): bool
    {
        return match ($checkType) {
            // Group or world has ANY permission (target 0600)
            'strict_private' => ($currentMode & 0077) !== 0,

            // Group or world has ANY permission (target 0700)
            'strict_private_dir' => ($currentMode & 0077) !== 0,

            // World has ANY read, write, or exec permission (target 0660)
            'restricted_db' => ($currentMode & 0007) !== 0,

            // World has ANY read, write, or exec permission (target 0750)
            'restricted_dir' => ($currentMode & 0007) !== 0,

            // World has write permission (target 0755)
            'no_world_write' => ($currentMode & 0002) !== 0,

            default => false,
        };
    }

    /**
     * Format a path relative to base_path for clean reporting.
     */
    protected function formatPath(string $path): string
    {
        $base = base_path() . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $base)) {
            return substr($path, strlen($base));
        }

        return $path;
    }
}
