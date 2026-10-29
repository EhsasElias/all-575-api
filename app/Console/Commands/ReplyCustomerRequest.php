<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\CustomerRequest;
use Illuminate\Console\Command;

class ReplyCustomerRequest extends Command
{
    protected $signature = 'customers:reply {id} {status : under_review|resolved|rejected} {reply}';

    protected $description = 'Record the operations response to a sourcing, data or account request';

    public function handle(): int
    {
        $status = (string) $this->argument('status');
        $reply = trim((string) $this->argument('reply'));
        if (! in_array($status, ['under_review', 'resolved', 'rejected'], true) || strlen($reply) < 3 || strlen($reply) > 5000) {
            $this->error('Choose a valid status and a reply between 3 and 5000 characters.');

            return self::FAILURE;
        }
        CustomerRequest::query()->findOrFail($this->argument('id'))->update(['status' => $status, 'reply' => $reply]);
        $this->info('Response recorded. Resolving a request does not delete data or create a discounted order.');

        return self::SUCCESS;
    }
}
