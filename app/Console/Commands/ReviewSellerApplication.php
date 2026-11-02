<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Seller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReviewSellerApplication extends Command
{
    protected $signature = 'seller:review {id} {action} {--note=} {--verified-documents}';
    protected $description = 'Review a submitted seller application from the trusted console.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $note = trim((string) $this->option('note'));
        if (! in_array($action, ['approve', 'correction', 'reject'], true)) return self::FAILURE;
        if ($action !== 'approve' && $note === '') return self::FAILURE;
        if ($action === 'approve' && ! $this->option('verified-documents')) return self::FAILURE;

        $id = (int) $this->argument('id');
        if ($id < 1) return self::FAILURE;

        DB::transaction(function () use ($id, $action, $note): void {
            $application = DB::table('seller_applications')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($application->status !== 'submitted') {
                throw new \RuntimeException('Application is not submitted.');
            }

            $sellerId = null;
            if ($action === 'approve') {
                $seller = Seller::query()->create([
                    'slug' => 'seller-'.$id,
                    'name_ar' => $application->legal_name,
                    'name_en' => $application->legal_name,
                    'status' => 'approved',
                ]);
                $sellerId = $seller->id;
                DB::table('seller_staff_access')->insert([
                    'user_id' => $application->user_id,
                    'seller_id' => $sellerId,
                    'role' => 'owner',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('seller_applications')->where('id', $id)->update([
                'status' => $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'correction'),
                'seller_id' => $sellerId,
                'review_note' => $note,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('audit_logs')->insert([
                'action' => 'seller.application.'.$action,
                'subject_type' => 'seller_application',
                'subject_id' => (string) $id,
                'context' => json_encode(['seller_id' => $sellerId]),
                'created_at' => now(),
            ]);
        });

        $this->info('Application reviewed.');
        return self::SUCCESS;
    }
}
