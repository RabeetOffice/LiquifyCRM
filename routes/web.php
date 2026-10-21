<?php

declare(strict_types=1);

use App\Modules\Activity\Http\Controllers\LogDownloadController;
use App\Modules\Activity\Livewire\LogsPage;
use App\Modules\Auth\Livewire\Admin\PeopleIndex;
use App\Modules\Auth\Livewire\Admin\PersonForm;
use App\Modules\Auth\Livewire\Profile;
use App\Modules\Auth\Livewire\ProfileSecurity;
use App\Modules\Brands\Http\Controllers\BrandRedirectController;
use App\Modules\Brands\Http\Controllers\FetchIdentityController;
use App\Modules\Brands\Livewire\Admin\BrandForm;
use App\Modules\Brands\Livewire\Admin\BrandsIndex;
use App\Modules\Brands\Livewire\Admin\BrandWizard;
use App\Modules\Brands\Livewire\App\EmailSettings;
use App\Modules\Brands\Livewire\App\MemberForm;
use App\Modules\Brands\Livewire\App\MembersIndex;
use App\Modules\Core\Http\Controllers\MenuBadgesController;
use App\Modules\Core\Http\Controllers\PushSubscriptionController;
use App\Modules\Core\Http\Controllers\TimezonesController;
use App\Modules\Core\Livewire\AdminDashboard;
use App\Modules\Core\Livewire\BrandDashboard;
use App\Modules\Departments\Livewire\App\DepartmentForm;
use App\Modules\Departments\Livewire\App\DepartmentsIndex;
use App\Modules\Leads\Http\Controllers\RevealContactController;
use App\Modules\Leads\Livewire\App\FollowUps;
use App\Modules\Leads\Livewire\App\LeadForm;
use App\Modules\Leads\Livewire\App\LeadsBin;
use App\Modules\Leads\Livewire\App\LeadShow;
use App\Modules\Leads\Livewire\App\LeadsIndex;
use App\Modules\Leads\Livewire\App\Pipeline;
use App\Modules\Meta\Http\Controllers\ConversationMessageController;
use App\Modules\Meta\Http\Controllers\FacebookConnectController;
use App\Modules\Meta\Livewire\Admin\IntegrationsPage;
use App\Modules\Meta\Livewire\App\Inbox;
use App\Modules\Resources\Http\Controllers\ResourceFileController;
use App\Modules\Resources\Livewire\App\ResourcesIndex;
use App\Modules\Sales\Http\Controllers\ClientFileController;
use App\Modules\Sales\Http\Controllers\RecordSaleController;
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
use App\Modules\System\Livewire\Admin\UpdatesPage;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/b')->name('home');

// Public reading pages: anyone may open them, and the Meta app links to both.
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/data-deletion', 'legal.data-deletion')->name('legal.data-deletion');

Route::middleware(['auth', 'two-factor'])->group(function (): void {
    Route::get('/profile', Profile::class)->name('profile');
    Route::get('/profile/security', ProfileSecurity::class)->name('profile.security');
    // The sidebar's counts outside a brand (the admin area, the profile).
    Route::get('/menu-badges', MenuBadgesController::class)->name('menu-badges');
    // Every time zone, for the searches of the clocks and the follow-ups.
    Route::get('/time-zones', TimezonesController::class)->name('time-zones');

    // This browser's address for desktop alerts with LiquifyCRM closed (Web Push).
    Route::post('/push/subscription', [PushSubscriptionController::class, 'store'])->middleware('throttle:30,1')->name('push.subscribe');
    Route::delete('/push/subscription', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:30,1')->name('push.unsubscribe');

    // Admin area: everything that is not inside one brand.
    Route::middleware('global-admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', AdminDashboard::class)->name('dashboard');
        Route::get('/brands', BrandsIndex::class)->name('brands.index');
        Route::get('/brands/create/{brandId?}', BrandWizard::class)->whereNumber('brandId')->name('brands.create');
        Route::get('/brands/{brandId}/edit', BrandForm::class)->name('brands.edit');
        Route::post('/sites/{site}/check', SiteCheckController::class)->whereNumber('site')->name('sites.check');
        Route::get('/integrations', IntegrationsPage::class)->name('integrations');
        Route::get('/logs', LogsPage::class)->name('logs');
        Route::get('/logs/archives/{archive}', [LogDownloadController::class, 'archive'])->whereNumber('archive')->name('logs.archives.download');
        Route::get('/sales', SalesDashboard::class)->name('sales');
        Route::post('/brands/{brandId}/fetch-identity', FetchIdentityController::class)->whereNumber('brandId')->name('brands.fetch-identity');

        // People and the System screen: every Super Admin and Admin (since 9 October).
        Route::get('/people', PeopleIndex::class)->name('people.index');
        Route::get('/people/create', PersonForm::class)->name('people.create');
        Route::get('/people/{userId}/edit', PersonForm::class)->whereNumber('userId')->name('people.edit');
        Route::redirect('/admins', '/admin/people');
        Route::get('/system', SystemPage::class)->name('system');
        Route::get('/updates', UpdatesPage::class)->name('updates');
    });

    // A person's own log download, while its 24 hours last.
    Route::get('/logs/exports/{export}', [LogDownloadController::class, 'export'])->whereNumber('export')->name('logs.exports.download');

    // Connect Facebook: once, globally, by a Super Admin or Admin.
    Route::middleware('global-admin')->prefix('integrations/facebook')->name('integrations.facebook.')->group(function (): void {
        Route::get('/connect', [FacebookConnectController::class, 'connect'])->name('connect');
        Route::get('/callback', [FacebookConnectController::class, 'callback'])->name('callback');
    });

    // Brand workspace: Brand is the tenant, every screen is scoped to it.
    Route::get('/b', BrandRedirectController::class)->name('brands.pick');

    Route::middleware('brand.member')->prefix('b/{brand}')->name('brand.')->group(function (): void {
        Route::get('/', BrandDashboard::class)->name('dashboard');
        // The sidebar's counts as they are now, asked for by app.js when something changed.
        Route::get('/menu-badges', MenuBadgesController::class)->name('menu-badges');

        Route::get('/leads', LeadsIndex::class)->name('leads.index');
        Route::get('/leads/create', LeadForm::class)->name('leads.create');
        Route::get('/leads/pipeline', Pipeline::class)->name('leads.pipeline');
        Route::get('/leads/distribute', DistributePage::class)->name('leads.distribute');
        Route::get('/leads/bin', LeadsBin::class)->name('leads.bin');
        Route::get('/leads/{lead}', LeadShow::class)->name('leads.show');
        Route::post('/leads/{lead}/won', RecordSaleController::class)->name('leads.won');
        Route::post('/leads/{lead}/reveal', RevealContactController::class)->name('leads.reveal');
        Route::get('/logs', LogsPage::class)->name('logs');
        Route::get('/follow-ups', FollowUps::class)->name('follow-ups');
        Route::get('/sales', SalesDashboard::class)->name('sales');
        Route::get('/targets', TargetsPage::class)->name('targets');
        Route::get('/clients', ClientsIndex::class)->name('clients.index');
        Route::get('/clients/{client}', ClientShow::class)->name('clients.show');
        Route::get('/clients/{client}/files/{file}', ClientFileController::class)->whereNumber('file')->name('clients.files.show');
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

        Route::get('/resources', ResourcesIndex::class)->name('resources.index');
        Route::get('/resources/{resource}/file', ResourceFileController::class)->whereNumber('resource')->name('resources.file');
    });
});
