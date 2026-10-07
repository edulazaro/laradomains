<?php

namespace EduLazaro\Laradomains\Console;

use EduLazaro\Laradomains\Rdap\RdapClient;
use EduLazaro\Laradomains\Support\PublicSuffixList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads the two lists the package reads from disk: the Public Suffix List (also compiled to
 * a PHP array for opcache) and the IANA RDAP bootstrap. Both change a few times a month at
 * most; a monthly schedule is plenty. A download that does not look like the real thing is
 * discarded and the current copy kept.
 */
class UpdateLists extends Command
{
    protected $signature = 'laradomains:update';

    protected $aliases = ['laradomains:update-suffixes'];

    protected $description = 'Download the latest Public Suffix List and IANA RDAP bootstrap';

    /**
     * @return int
     */
    public function handle(): int
    {
        $ok = $this->suffixes();

        return $this->bootstrap() && $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return bool
     */
    private function suffixes(): bool
    {
        $path = (string) config('laradomains.public_suffix_list');

        try {
            $contents = Http::timeout(30)->get('https://publicsuffix.org/list/public_suffix_list.dat')->throw()->body();
        } catch (Throwable $e) {
            $this->error('Public Suffix List not updated: '.$e->getMessage());

            return false;
        }

        // A truncated download or an error page would quietly break every registrable() call.
        if (! str_contains($contents, '===END PRIVATE DOMAINS===')) {
            $this->error('The downloaded Public Suffix List looks incomplete; keeping the current one.');

            return false;
        }

        $this->write($path, $contents);
        PublicSuffixList::fromString($contents)->compileTo(PublicSuffixList::compiledPath($path));
        PublicSuffixList::flush();

        $this->info('Public Suffix List updated: '.$path);

        return true;
    }

    /**
     * @return bool
     */
    private function bootstrap(): bool
    {
        $path = (string) config('laradomains.rdap.bootstrap_path');

        try {
            $contents = Http::timeout(30)->get((string) config('laradomains.rdap.bootstrap'))->throw()->body();
        } catch (Throwable $e) {
            $this->error('RDAP bootstrap not updated: '.$e->getMessage());

            return false;
        }

        if (RdapClient::map($contents) === null) {
            $this->error('The downloaded RDAP bootstrap is not valid; keeping the current one.');

            return false;
        }

        $this->write($path, $contents);
        RdapClient::flush();

        $this->info('RDAP bootstrap updated: '.$path);

        return true;
    }

    /**
     * @param string $path
     * @param string $contents
     * @return void
     */
    private function write(string $path, string $contents): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path.'.tmp', $contents);
        rename($path.'.tmp', $path);
    }
}
