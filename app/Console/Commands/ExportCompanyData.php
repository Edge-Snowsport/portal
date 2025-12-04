<?php

namespace Crater\Console\Commands;

use Barryvdh\DomPDF\Facade as PDF;
use Crater\Models\Company;
use Crater\Models\CustomField;
use Crater\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Log;
use function dirname;

class ExportCompanyData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'export:company-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export all invoice PDFs and expense items for each company';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting export...');

        // For long-running CLI jobs
        ini_set('memory_limit', '1024M');
        set_time_limit(0);

        // Avoid query log growing unbounded
        DB::connection()->disableQueryLog();

        $invoiceCount = Invoice::count();
        $bar = $this->output->createProgressBar($invoiceCount);
        $bar->start();

        // Load once per run, not per invoice
        $customFields = CustomField::where('model_type', 'Item')->get();

        Company::chunkById(50, function ($companies) use ($bar, $customFields) {
            foreach ($companies as $company) {
                Log::info("Processing company: $company->name");

                // Use ID + slug to avoid bad path characters
                $companyDirName = $company->id . '_' . Str::slug($company->name);
                $companyDir = "exports/$companyDirName";
                Storage::disk('local')->makeDirectory($companyDir);

                $this->exportInvoicesForCompany($company, $companyDir, $bar, $customFields);
                $this->exportExpensesForCompany($company, $companyDir);
            }

            // Encourage GC between batches
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        });

        $bar->finish();
        $this->info("\nExport complete.");

        return 0;
    }

    /**
     * Export all invoices for a given company as PDFs.
     */
    protected function exportInvoicesForCompany(Company $company, string $companyDir, $bar, $customFields): void
    {
        Log::info("Fetching invoices for company: $company->name");

        $template = $company->name === 'Edge Snowsport' ? 'invoice-custom' : 'invoice1';
        $logo = $company->logo_path;

        $company->invoices()
            ->with(['customer', 'items', 'taxes'])
            ->orderBy('id')
            ->lazyById(100) // ID-based streaming without OFFSET
            ->each(function ($invoice) use ($company, $companyDir, $bar, $template, $logo, $customFields) {
                Log::info("  - Exporting invoice: $invoice->invoice_number");

                Log::info("    - Generating PDF for invoice: $invoice->invoice_number");
                $pdf = PDF::loadView('app.pdf.invoice.' . $template, [
                    'invoice'          => $invoice,
                    'logo'             => $logo,
                    'company_address'  => $invoice->getCompanyAddress(),
                    'shipping_address' => $invoice->getCustomerShippingAddress(),
                    'billing_address'  => $invoice->getCustomerBillingAddress(),
                    'notes'            => $invoice->getNotes(),
                    'taxes'            => $invoice->taxes,
                    'customFields'     => $customFields,
                ]);
                Log::info("    - PDF generated for invoice: $invoice->invoice_number");

                $pdfPath = "$companyDir/invoice_$invoice->invoice_number.pdf";
                Log::info("    - Saving PDF to: $pdfPath");

                Storage::disk('local')->put($pdfPath, $pdf->output());
                Log::info("    - PDF saved to: $pdfPath");

                // Free DomPDF object memory
                unset($pdf);
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }

                $bar->advance();
            });
    }

    /**
     * Export all expenses for a given company as a CSV.
     */
    protected function exportExpensesForCompany(Company $company, string $companyDir): void
    {
        Log::info("Fetching expenses for company: $company->name");

        $csvPath = "$companyDir/expenses.csv";
        $disk = Storage::disk('local');
        $fullPath = $disk->path($csvPath);

        // Ensure directory exists
        @mkdir(dirname($fullPath), 0775, true);

        $csvFile = fopen($fullPath, 'w');
        if ($csvFile === false) {
            Log::error("  - Failed to open CSV for writing: $fullPath");
            return;
        }

        fputcsv($csvFile, ['Date', 'Category', 'Amount', 'Notes']);

        $company->expenses()
            ->with('category')
            ->orderBy('id')
            ->cursor() // stream one row at a time
            ->each(function ($expense) use ($csvFile) {
                fputcsv($csvFile, [
                    optional($expense->expense_date)->format('Y-m-d'),
                    optional($expense->category)->name,
                    $expense->amount,
                    $expense->notes,
                ]);
            });

        fclose($csvFile);
        Log::info("  - Expenses exported to: $csvPath");
    }
}
