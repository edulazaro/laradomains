<?php

namespace EduLazaro\Laradomains\Console;

use EduLazaro\Laradomains\Support\PublicSuffixList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Downloads the current Public Suffix List to the configured path. The list changes a few
 * times a month (new gTLDs, hosting platforms); a monthly schedule is plenty.
 */
class UpdateSuffixes extends Command
{
    protected $signature = 'laradomains:update-suffixes';

    protected $description = 'Download the latest Public Suffix List';

    /**
     * @return int
     */
    public function handle(): int
    {
        $contents = Http::timeout(30)->get('https://publicsuffix.org/list/public_suffix_list.dat')->throw()->body();

        // A truncated or error page would quietly break every registrable() call.
        if (! str_contains($contents, '===END PRIVATE DOMAINS===')) {
            $this->error('The downloaded list looks incomplete; keeping the current one.');

            return self::FAILURE;
        }

        $path = (string) config('laradomains.public_suffix_list');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path.'.tmp', $contents);
        rename($path.'.tmp', $path);
        PublicSuffixList::flush();

        $this->info('Public Suffix List updated: '.$path);

        return self::SUCCESS;
    }
}
