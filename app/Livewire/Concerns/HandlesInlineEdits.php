<?php

namespace App\Livewire\Concerns;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

trait HandlesInlineEdits
{
    /**
     * Execute a narrow inline edit without leaking technical exceptions to the UI.
     *
     * JavaScript-consumed persistence actions should be marked #[Json], so the caller
     * receives an explicit confirmation payload without morphing the component.
     */
    protected function persistInlineEdit(string $label, Closure $callback): array
    {
        try {
            // Inline edits are atomic. If validation, activity recording, or any
            // other synchronous persistence step fails, the optimistic UI can
            // safely restore the previous value because the database rolls back.
            DB::transaction($callback, 2);

            return [
                'ok' => true,
                'message' => $label.' saved.',
            ];
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'message' => collect($exception->errors())->flatten()->first()
                    ?: 'Please check the '.$label.' value and try again.',
                // Preserve Laravel's keyed error bag so Alpine/Livewire popup
                // clients can place each message directly under its field.
                'errors' => $exception->errors(),
            ];
        } catch (AuthorizationException $exception) {
            return [
                'ok' => false,
                'message' => 'You do not have permission to change '.$label.'.',
            ];
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getStatusCode();

            if ($status === 403) {
                return ['ok' => false, 'message' => 'You do not have permission to change '.$label.'.'];
            }

            if ($status === 404) {
                return ['ok' => false, 'message' => 'This record is no longer available. Refresh the page and try again.'];
            }

            if ($status === 422) {
                $message = trim($exception->getMessage());

                if ($message === \App\Services\Orders\OrderHoldService::BLOCKED_ACTIVITY_MESSAGE
                    && method_exists($this, 'dispatch')) {
                    $this->dispatch('flowtrack:order-held-blocked', action: $label);
                }

                return [
                    'ok' => false,
                    'message' => $message !== '' ? $message : 'Please check the '.$label.' value and try again.',
                ];
            }

            report($exception);

            return ['ok' => false, 'message' => 'Could not save '.$label.' right now. Your previous value was restored. Please retry.'];
        } catch (QueryException $exception) {
            report($exception);

            return ['ok' => false, 'message' => 'Could not save '.$label.' because the record changed or is currently in use. Refresh the page or retry.'];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'message' => 'Could not save '.$label.' right now. Your previous value was restored. Please retry.'];
        }
    }
}
