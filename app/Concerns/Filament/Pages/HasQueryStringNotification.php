<?php

namespace App\Concerns\Filament\Pages;

use App\Enums\QueryParameter;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Show a notification a caller asked for on the query string.
 *
 * The CLI drain paths run outside this window's own request and share no session with it, so the
 * message they want shown has to travel on the URL they navigate to.
 */
trait HasQueryStringNotification
{
    protected function sendQueryStringNotification(): void
    {
        $this->sendNotificationFromQueryString();
        $this->clearQueryStringNotificationParameters();
    }

    private function sendNotificationFromQueryString(): void
    {
        $error = $this->queryStringValue(QueryParameter::ERROR);

        if ($error !== null) {
            Notification::make()
                ->danger()
                ->title($error)
                ->body($this->queryStringNotificationBody())
                ->icon(Heroicon::XCircle)
                ->persistent()
                ->send();

            return;
        }

        $success = $this->queryStringValue(QueryParameter::SUCCESS);

        if ($success === null) {
            return;
        }

        Notification::make()
            ->success()
            ->title($success)
            ->body($this->queryStringNotificationBody())
            ->icon(Heroicon::CheckCircle)
            ->persistent()
            ->send();
    }

    /**
     * Drop the parameters from the address bar once the message they carried has been shown.
     *
     * The CLI navigates the window to a URL holding the message, so without this a reload — the topbar
     * refresh button included — would re-mount the page and show the same stale notification again.
     */
    private function clearQueryStringNotificationParameters(): void
    {
        $parameters = array_column(QueryParameter::cases(), 'value');

        if (array_intersect_key(request()->query(), array_flip($parameters)) === []) {
            return;
        }

        $encodedParameters = json_encode($parameters);

        // Alpine only wraps an expression in an async closure when it opens with `let`, `const` or
        // `if (`, so this statement list has to start with the declaration to parse at all
        $this->js(<<<JS
            const url = new URL(window.location.href);
            {$encodedParameters}.forEach((parameter) => url.searchParams.delete(parameter));
            window.history.replaceState({}, '', url);
        JS);
    }

    /**
     * The body is assembled from file paths and validation messages that originate in a
     * user-authored YAML file, so it is escaped before its newlines become markup.
     */
    private function queryStringNotificationBody(): ?HtmlString
    {
        $body = $this->queryStringValue(QueryParameter::BODY);

        if ($body === null) {
            return null;
        }

        return new HtmlString(nl2br(e($body)));
    }

    /**
     * A non-empty string from the query string, or null.
     */
    private function queryStringValue(QueryParameter $parameter): ?string
    {
        $value = request()->query($parameter->value);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
