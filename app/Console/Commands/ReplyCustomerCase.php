<?php

namespace App\Console\Commands;

use App\Domain\Aftercare\Models\CustomerCase;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('care:reply {id} {message} {--status=in_review : in_review|resolved|rejected}')]
#[Description('Simulate a support reply on a local/testing server; never issues a refund')]
class ReplyCustomerCase extends Command
{
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('care:reply is available only in local/testing environments.');

            return self::FAILURE;
        }
        $status = (string) $this->option('status');
        $body = trim((string) $this->argument('message'));
        if (! in_array($status, ['in_review', 'resolved', 'rejected'], true) || $body === '' || mb_strlen($body) > 3000) {
            $this->error('Choose a valid status and a message of 1-3000 characters.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($status, $body): void {
            $case = CustomerCase::query()->whereKey($this->argument('id'))->lockForUpdate()->firstOrFail();
            $case->messages()->create(['author' => 'support', 'body' => $body]);
            $case->update(['status' => $status]);
        });
        $this->info('Reply saved. No payment, refund or stock was changed.');

        return self::SUCCESS;
    }
}
