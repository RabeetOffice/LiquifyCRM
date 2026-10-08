<?php

declare(strict_types=1);

use App\Modules\Auth\Livewire\Admin\AdminAccountForm;
use App\Modules\Auth\Livewire\Admin\AdminAccountsIndex;
use App\Modules\Auth\Livewire\Profile;
use App\Modules\Auth\Livewire\ProfileSecurity;
use App\Modules\Brands\Http\Controllers\BrandRedirectController;
use App\Modules\Brands\Http\Controllers\FetchIdentityController;
use App\Modules\Brands\Livewire\Admin\BrandForm;
use App\Modules\Brands\Livewire\Admin\BrandsIndex;
use App\Modules\Brands\Livewire\App\EmailSettings;
use App\Modules\Brands\Livewire\App\MemberForm;
use App\Modules\Brands\Livewire\App\MembersIndex;
use App\Modules\Core\Livewire\AdminDashboard;
use App\Modules\Core\Livewire\BrandDashboard;
use App\Modules\Departments\Livewire\App\DepartmentForm;
use App\Modules\Departments\Livewire\App\DepartmentsIndex;
use App\Modules\Leads\Livewire\App\FollowUps;
use App\Modules\Leads\Livewire\App\LeadForm;
use App\Modules\Leads\Livewire\App\LeadShow;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Meta\Http\Controllers\ConversationMessageController;
use App\Modules\Meta\Http\Controllers\FacebookConnectController;
use App\Modules\Meta\Livewire\Admin\IntegrationsPage;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Sales\Http\Controllers\RecordSaleController;
use App\Modules\Sales\Livewire\Admin\ExchangeRatesPage;
use App\Modules\Sales\Livewire\App\ClientShow;
use App\Modules\Sales\Livewire\App\ClientsIndex;
use App\Modules\Sales\Livewire\App\DistributePage;
use App\Modules\Sales\Livewire\App\TargetsPage;
use App\Modules\Sales\Livewire\SalesDashboard;
use App\Modules\Sites\Http\Controllers\SiteCheckController;
use App\Modules\Sites\Http\Controllers\SiteSnippetController;
use App\Modules\Sites\Livewire\App\SiteShow;
use App\Modules\Sites\Livewire\App\SitesIndex;
use App\Modules\Sites\Livewire\App\SiteWizard;
use App\Modules\System\Livewire\Admin\SystemPage;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/b')->name('home');

Route::middleware(['auth', 'two-factor'])->group(function (): void {
    Route::get('/profile', Profile::class)->name('profile');
    Route::get('/profile/security', ProfileSecurity::class)->name('profile.security');

    // Admin area: everything that is not inside one brand.
    Route::middleware('global-admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', AdminDashboard::class)->name('dashboard');
        Route::get('/brands', BrandsIndex::class)->name('brands.index');
        Route::get('/brands/create', BrandForm::class)->name('brands.create');
        Route::get('/brands/{brandId}/edit', BrandForm::class)->name('brands.edit');
        Route::post('/sites/{site}/check', SiteCheckController::class)->whereNumber('site')->name('sites.check');
        Route::get('/integrations', IntegrationsPage::class)->name('integrations');
        Route::get('/sales', SalesDashboard::class)->name('sales');
        Route::get('/exchange-rates', ExchangeRatesPage::class)->name('exchange-rates');
        Route::post('/brands/{brandId}/fetch-identity', FetchIdentityController::class)->whereNumber('brandId')->name('brands.fetch-identity');

        Route::middleware('super-admin')->group(function (): void {
            Route::get('/admins', AdminAccountsIndex::class)->name('admins.index');
            Route::get('/admins/create', AdminAccountForm::class)->name('admins.create');
            Route::get('/admins/{userId}/edit', AdminAccountForm::class)->name('admins.edit');
            Route::get('/system', SystemPage::class)->name('system');
        });
    });

    // Connect Facebook: once, globally, by a Super Admin or Admin.
    Route::middleware('global-admin')->prefix('integrations/facebook')->name('integrations.facebook.')->group(function (): void {
        Route::get('/connect', [FacebookConnectController::class, 'connect'])->name('connect');
        Route::get('/callback', [FacebookConnectController::class, 'callback'])->name('callback');
    });

    // Brand workspace: Brand is the tenant, every screen is scoped to it.
    Route::get('/b', BrandRedirectController::class)->name('brands.pick');

    Route::middleware('brand.member')->prefix('b/{brand}')->name('brand.')->group(function (): void {
        Route::get('/', BrandDashboard::class)->name('dashboard');

        Route::get('/leads', LeadsIndex::class)->name('leads.index');
        Route::get('/leads/create', LeadForm::class)->name('leads.create');
        Route::get('/leads/pipeline', Pipeline::class)->name('leads.pipeline');
        Route::get('/leads/distribute', DistributePage::class)->name('leads.distribute');
        Route::get('/leads/{lead}', LeadShow::class)->name('leads.show');
        Route::post('/leads/{lead}/won', RecordSaleController::class)->name('leads.won');
        Route::get('/follow-ups', FollowUps::class)->name('follow-ups');
        Route::get('/sales', SalesDashboard::class)->name('sales');
        Route::get('/targets', TargetsPage::class)->name('targets');
        Route::get('/clients', ClientsIndex::class)->name('clients.index');
        Route::get('/clients/{client}', ClientShow::class)->name('clients.show');
        Route::get('/inbox', Inbox::class)->name('inbox');
        Route::post('/conversations/{conversation}/messages', ConversationMessageController::class)->name('conversations.messages');

        Route::get('/departments', DepartmentsIndex::class)->name('departments.index');
        Route::get('/departments/create', DepartmentForm::class)->name('departments.create');
        Route::get('/departments/{department}/edit', DepartmentForm::class)->name('departments.edit');

        Route::get('/members', MembersIndex::class)->name('members.index');
        Route::get('/members/create', MemberForm::class)->name('members.create');

        Route::get('/sites', SitesIndex::class)->name('sites.index');
        Route::get('/sites/connect/{site?}', SiteWizard::class)->name('sites.connect');
        Route::get('/sites/{site}', SiteShow::class)->name('sites.show');
        Route::get('/sites/{site}/download', SiteSnippetController::class)->name('sites.download');
        Route::get('/settings/email', EmailSettings::class)->name('email-settings');
    });
});
