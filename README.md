# HR e-invoicing for Laravel

Croatian e-invoices (Fiskalizacija 2.0) for Laravel: write UBL 2.1 documents under the Croatian CIUS 2025 and send
them through the Moj-eRačun intermediary.

*Hrvatski e-računi (Fiskalizacija 2.0) za Laravel: UBL 2.1 dokumenti prema HR CIUS 2025, slanje putem Moj-eRačuna.*

The package gives you:

- **A Moj-eRačun client**: send a document, look up its status and the tax authority's answer, find a document whose
  answer was lost, report a payment, test the connection.
- **The parts every Croatian e-invoice shares**, written the way Moj-eRačun's examples write them: the document with its
  namespaces, the Croatian extension (`HRFISK20Data`), the parties by OIB, the VAT breakdown, the totals and the lines.
  You map your own invoices onto them.
- **For your tests**: a fake gateway, and a check of documents against the UBL 2.1 schemas with the Croatian
  extension.

Invoices, stornos, advance invoices and credit notes written with it have passed Moj-eRačun's validation on its demo
service.

## Requirements

- PHP 8.3+ with the `dom` and `libxml` extensions
- Laravel 12 or 13
- A Moj-eRačun account and the software identifier Moj-eRačun issues for your software

## Installation

```bash
composer require gtcrais/hr-einvoicing
php artisan vendor:publish --tag=hr-einvoicing-config
```

The service provider is discovered automatically.

## Configuration

`config/hrEInvoicing.php` reads these from `.env`:

| Variable | Meaning |
| --- | --- |
| `HR_EINVOICING_GATEWAY` | `mojeracun` (default), or `fake` for tests |
| `MOJ_ERACUN_ENVIRONMENT` | `demo` (default) or `production` |
| `MOJ_ERACUN_USERNAME` | The Moj-eRačun account's username |
| `MOJ_ERACUN_PASSWORD` | Its password |
| `MOJ_ERACUN_OIB` | The issuer's OIB |
| `MOJ_ERACUN_BUSINESS_UNIT` | The issuer's business unit, if it has one |
| `MOJ_ERACUN_SOFTWARE_ID` | The software identifier Moj-eRačun issued |

`attemptSeconds` (15 by default) caps each call to Moj-eRačun.

## Sending e-invoices

Resolve the gateway from the container:

```php
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\Exceptions\EInvoiceRefusedException;

$gateway = app(EInvoiceGateway::class);

try {
    ['electronicId' => $id, 'status' => $status] = $gateway->send($xml);
} catch (EInvoiceRefusedException $exception) {
    // Moj-eRačun refused the document itself (HTTP 400 or 422); the message lists the rules it broke.
    // Sending the same document again would be refused again.
} catch (RuntimeException $exception) {
    // Moj-eRačun could not be reached or answered with an error; a later try may pass.
}
```

| Method | What it does |
| --- | --- |
| `ready(): bool` | Whether the account is complete |
| `send(string $xml): array` | Sends the document; returns `electronicId` and Moj-eRačun's `status` |
| `sentDocument(string $number, DateTimeInterface $since): ?array` | Finds a document sent since then by its number, for a send whose answer was lost |
| `status(string $electronicId): array` | The document's `status`, and the tax authority's answer: `fiscalization` (null while unknown, 0 success, 1 failure, 2 waiting) with its `error` |
| `reportPayment(string $electronicId, string $paidOn, float $amount): void` | Reports a payment by transfer to the tax authority |
| `ping(): void` | Tests the connection; throws `EInvoiceUnavailableException` when the service itself is down |

Every call throws a `RuntimeException` with the reason when it fails.

### One account per tenant

The calls carry the configured account. An app that sends for many companies binds its own account provider instead.
The client asks it again before every call:

```php
use GTCrais\HrEInvoicing\MojEracun\Contracts\AccountProvider;

class TenantEInvoiceAccount implements AccountProvider
{
    public function account(): ?array
    {
        $tenant = tenant();

        if (blank($tenant->moj_eracun_username)) {
            return null;
        }

        return [
            'username' => $tenant->moj_eracun_username,
            'password' => $tenant->moj_eracun_password,
            'companyId' => $tenant->oib,
            'companyBU' => null,
            'softwareId' => config('hrEInvoicing.mojEracun.softwareId'),
        ];
    }
}

// In a service provider's register()
$this->app->bind(AccountProvider::class, TenantEInvoiceAccount::class);
```

## Writing documents

Use the `WritesHrUbl` trait in a class that maps your invoices onto the document. UBL fixes the order of the elements,
so call the methods in this order:

```php
use GTCrais\HrEInvoicing\Ubl\Concerns\WritesHrUbl;
use GTCrais\HrEInvoicing\Ubl\HrUbl;

class InvoiceXml
{
    use WritesHrUbl;

    public function build(Invoice $invoice): string
    {
        $lines = $this->lines($invoice); // your mapping, see below
        $categories = $this->categories($lines);
        $net = round(array_sum(array_column($lines, 'net')), 2);
        $tax = round(array_sum(array_column($lines, 'tax')), 2);
        $gross = round($net + $tax, 2);

        $root = $this->document();

        $this->extensions($root, $categories, $net, $tax, 'EUR', vatRegistered: true);
        $this->heading($root, HrUbl::PROFILE_INVOICE, $invoice->number, $invoice->issued_at->setTimezone('Europe/Zagreb'));
        $this->leaf($root, 'cbc:DueDate', $invoice->due_on->toDateString());
        $this->leaf($root, 'cbc:InvoiceTypeCode', (string) HrUbl::TYPE_INVOICE);
        $this->leaf($root, 'cbc:DocumentCurrencyCode', 'EUR');
        $this->supplierParty($root, $this->seller(), vatRegistered: true, operatorOib: '12345678903', operatorName: 'Ana Horvat');
        $this->customerParty($root, $this->buyer($invoice));
        $this->delivery($root, $invoice->delivered_on->toDateString());
        $this->paymentMeans($root, $invoice->number, 'HR1210010051863000160');
        $this->taxTotal($root, $categories, $tax, 'EUR');
        $this->monetaryTotal($root, $net, $gross, $gross, 'EUR');

        foreach ($lines as $line) {
            $this->line($root, $line, 'EUR');
        }

        return (string) $root->ownerDocument->saveXML();
    }
}
```

The arrays the methods take:

- **A party**: `oib`, `name` (the legal name), `street`, `city`, `postalCode`, `country` (`HR` for a Croatian
  company, which also gets its VAT identifier).
- **A line**: `id` (1, 2, …), `name`, `quantity`, `net` (after discounts), `tax`, `unit` (the net unit price), `kpd`
  (the KPD 2025 classification code, or null) and `category`.
- **A VAT category**: `key` (anything that tells categories apart), `id` (`S` standard, `E` exempt), `name`
  (`HR:PDV25`, `HR:E`), `percent`, and for an exemption its `code` (VATEX) and `reason`. `categories()` adds up each
  category's `base` and `tax` from the lines and puts the standard rates first.

For the other kinds of documents:

- **A storno** is `HrUbl::TYPE_CORRECTIVE` under `HrUbl::PROFILE_CORRECTIVE`, with negative quantities and amounts and
  a `billingReference()` to the invoice it cancels.
- **An advance invoice** is `HrUbl::TYPE_ADVANCE` under `HrUbl::PROFILE_ADVANCE`. Pass what was paid as
  `monetaryTotal()`'s `prepaid`.
- **A credit note** is a `CreditNote` document: call `document(creditNote: true)`, write `cbc:CreditNoteTypeCode` in
  place of the due date and the invoice type code, and pass `creditNote: true` to `line()`.

An issuer outside the VAT system passes `vatRegistered: false`, gives every line an `E` category with the reason, and is
named by its OIB under the `FRE` scheme. The Croatian extension is written whenever a category is not standard.

Moj-eRačun signs the documents, so the signature block is left empty for it.

## Testing

Set `HR_EINVOICING_GATEWAY=fake` in `phpunit.xml`. The gateway is then a `Testing\FakeEInvoiceGateway`, one for the
whole run:

```php
use GTCrais\HrEInvoicing\Contracts\EInvoiceGateway;
use GTCrais\HrEInvoicing\Testing\UblSchema;

beforeEach(function () {
    $this->gateway = app(EInvoiceGateway::class);
    $this->gateway->reset();
});

it('sends the invoice', function () {
    $this->gateway->failNext();        // the next call fails as a timeout would
    $this->gateway->refuseNext();      // the next document is refused
    $this->gateway->loseNextAnswer();  // the next document arrives, but its answer is lost

    // ... send

    expect($this->gateway->sent)->toHaveCount(1)
        ->and(UblSchema::errors($this->gateway->sent[0]))->toBe([]);
});
```

The fake also records `payments` and `pings`, and `setStatus()` sets what it answers for a document.

`UblSchema::errors($xml)` checks a document against the UBL 2.1 Invoice or CreditNote schema with the Croatian
extension and returns what is wrong, or an empty array.

## What it does not do

- It issues e-invoices; it does not receive them.
- It talks to Moj-eRačun only. `EInvoiceGateway` leaves room for other intermediaries.
- It writes nothing outside the scope of VAT.

## Schemas

The XML schemas in `resources/ubl` are OASIS's UBL 2.1 and the Croatian Tax Administration's extension, included
unchanged. They keep their own terms and are not covered by this package's license; see
[resources/ubl/NOTICE.md](resources/ubl/NOTICE.md).

## License

MIT. See [LICENSE.md](LICENSE.md).
