<?php

namespace App\Filament\Widgets;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Process;

/**
 * The GPL-3 "Appropriate Legal Notices" (§0), which §5(d) requires an interactive
 * program to display: the copyright notice, the absence of warranty, the right to
 * redistribute under the GPL, and how to read the License itself.
 *
 * It deliberately has no canView() and no dismiss action — the notice is a condition
 * of conveying the program, not a tip the user can turn off.
 */
class LegalNoticesWidget extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected static ?int $sort = 5;

    protected string $view = 'filament.widgets.legal-notices-widget';

    protected int|string|array $columnSpan = 'full';

    public function viewLicenseAction(): Action
    {
        return Action::make('viewLicense')
            ->label('View the License')
            ->color('gray')
            ->action(function () {
                Process::run(['open', config('app.license_url')]);
            });
    }
}
