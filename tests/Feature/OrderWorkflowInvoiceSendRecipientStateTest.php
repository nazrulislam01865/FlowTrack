<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowInvoiceSendRecipientStateTest extends TestCase
{
    public function test_invoice_send_recipient_state_has_safe_defaults_before_the_variant_branch(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));

        $initialization = strpos($view, '$invoiceToSuggestions = collect();');
        $invoiceBranch = strpos($view, "@elseif(\$variant === 'invoice_send')");

        $this->assertNotFalse($initialization);
        $this->assertNotFalse($invoiceBranch);
        $this->assertLessThan($invoiceBranch, $initialization);
        $this->assertStringContainsString('$invoiceCcSuggestions = collect();', $view);
        $this->assertStringContainsString('$invoiceMatchedToUser = null;', $view);
        $this->assertStringContainsString('$invoiceToIsValidEmail = false;', $view);
        $this->assertStringContainsString('$invoiceNoSystemMatch = false;', $view);
    }

    public function test_invoice_send_recipient_lists_use_balanced_blade_control_directives(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));

        $this->assertStringContainsString('@if($invoiceToSuggestions->isNotEmpty())', $view);
        $this->assertStringContainsString('@foreach($invoiceToSuggestions as $option)', $view);
        $this->assertStringContainsString('@if($invoiceCcSuggestions->isNotEmpty())', $view);
        $this->assertStringContainsString('@foreach($invoiceCcSuggestions as $option)', $view);
        $this->assertStringContainsString('$invoiceCcSuggestionValues = collect();', $view);
        $this->assertStringContainsString('@js($invoiceCcSuggestionValues->get((int) $option[\'id\'], \'\'))', $view);
        $this->assertStringNotContainsString('<?php foreach ($invoiceToSuggestions as $option): ?>', $view);
        $this->assertStringNotContainsString('<?php foreach ($invoiceCcSuggestions as $option): ?>', $view);
    }
    public function test_billing_variants_do_not_contain_nested_php_directives_that_can_leak_as_text(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $start = strpos($view, "@elseif(\$variant === 'invoice_prepare')");
        $end = strpos($view, "@elseif(\$variant === 'payment')", $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $billingBlock = substr($view, $start, $end - $start);

        $this->assertStringNotContainsString('@php', $billingBlock);
        $this->assertStringNotContainsString('@endphp', $billingBlock);
        $this->assertStringNotContainsString('<?php', $billingBlock);
        $this->assertStringContainsString("@elseif(\$variant === 'invoice_send')", $billingBlock);
        $this->assertStringContainsString('Included order:', $billingBlock);
    }

}
