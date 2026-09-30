<?php

namespace Mpass\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PublishResolverCommand extends Command
{
    protected $signature   = 'mpass:publish-resolver';
    protected $description = 'Publish the MPass user resolver stub to app/MPass/MpassUserResolver.php';

    public function handle(): int
    {
        $target = app_path('MPass/MpassUserResolver.php');

        if (File::exists($target) && !$this->confirm('MpassUserResolver.php already exists. Overwrite?', false)) {
            $this->line('Skipped.');
            return self::SUCCESS;
        }

        File::ensureDirectoryExists(app_path('MPass'));
        File::copy(__DIR__ . '/../../stubs/MpassUserResolver.stub', $target);

        $this->info('Published: app/MPass/MpassUserResolver.php');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Customize <comment>app/MPass/MpassUserResolver.php</comment> to match your users table schema.');
        $this->line('  2. Register the resolver in your <comment>app/Providers/MpassServiceProvider.php</comment>:');
        $this->newLine();
        $this->line('     $this->app->bind(\\' . \Mpass\Contracts\UserResolverContract::class . '::class,');
        $this->line('                      \\App\\MPass\\MpassUserResolver::class);');

        return self::SUCCESS;
    }
}
