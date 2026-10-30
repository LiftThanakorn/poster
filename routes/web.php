<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// Non-blocking Web Queue Worker trigger for environments where shell exec is disabled
Route::get('/queue/work-trigger', function () {
    ignore_user_abort(true);
    @set_time_limit(60);
    try {
        $startTime = time();
        while (time() - $startTime < 45) {
            Artisan::call('queue:work', [
                '--once' => true,
                '--stop-when-empty' => true,
            ]);

            if (DB::table('jobs')->count() === 0) {
                break;
            }
        }

        return response()->json(['status' => 'completed']);
    } catch (Throwable $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
})->name('queue.work-trigger');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/posts/{post}/status', [PostController::class, 'status'])->name('posts.status');
    Route::get('/posts/{post}/timeline', [PostController::class, 'timeline'])->name('posts.timeline');
    Route::post('/posts/{post}/retry/{channel}', [PostController::class, 'retry'])->name('posts.retry');
    Route::post('/posts/autosave', [PostController::class, 'autosave'])->name('posts.autosave');
    Route::put('/posts/{post}/autosave', [PostController::class, 'updateAutosave'])->name('posts.autosave.update');
    Route::post('/posts/{post}/duplicate', [PostController::class, 'duplicate'])->name('posts.duplicate');
    Route::post('/posts/{post}/facebook-replacement', [PostController::class, 'facebookReplacement'])->name('posts.facebook-replacement');
    Route::post('/posts/{post}/facebook-replacement/delete-original', [PostController::class, 'deleteReplacedFacebook'])->name('posts.facebook-replacement.delete-original');
    Route::resource('posts', PostController::class);

    // Integrations
    Route::get('/settings/integrations', [IntegrationController::class, 'index'])->name('settings.integrations');
    Route::post('/settings/integrations', [IntegrationController::class, 'update'])->name('settings.integrations.update');
    Route::post('/settings/integrations/check/{channel}', [IntegrationController::class, 'check'])->name('settings.integrations.check');
    Route::get('/settings/integrations/google-drive/redirect', [IntegrationController::class, 'googleDriveRedirect'])->name('settings.integrations.google-drive.redirect');
    Route::get('/settings/integrations/google-drive/callback', [IntegrationController::class, 'googleDriveCallback'])->name('settings.integrations.google-drive.callback');
    Route::post('/settings/integrations/google-drive/disconnect', [IntegrationController::class, 'googleDriveDisconnect'])->name('settings.integrations.google-drive.disconnect');
    Route::post('/settings/integrations/google-drive/inspect-folders', [IntegrationController::class, 'googleDriveInspectFolders'])->name('settings.integrations.google-drive.inspect-folders');

    // Announcements Directory & Standalone Uploads
    Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::post('/announcements/{media}/sync', [AnnouncementController::class, 'sync'])->name('announcements.sync');

    // Media Manager
    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    Route::post('/media', [MediaController::class, 'store'])->name('media.store');
    Route::delete('/media/{id}', [MediaController::class, 'destroy'])->name('media.destroy');
    Route::post('/media/folders', [MediaController::class, 'storeFolder'])->name('media.folders.store');
    Route::put('/media/folders/{folder}', [MediaController::class, 'updateFolder'])->name('media.folders.update');
    Route::delete('/media/folders/{folder}', [MediaController::class, 'destroyFolder'])->name('media.folders.destroy');
    Route::post('/media/move', [MediaController::class, 'move'])->name('media.move');
    Route::put('/media/{media}', [MediaController::class, 'update'])->name('media.update');

    // Templates
    Route::resource('templates', TemplateController::class);
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
