<?php

use Illuminate\Support\Facades\Route;

Route::post('/payments/flip/callback', [\App\Http\Controllers\FlipWebhookController::class, 'handle'])
    ->name('flip.callback');

// Espay SNAP (Espay -> Merchant): tanpa session/CSRF/middleware yang mengubah response, plus CORS untuk ASPI Dev Site
Route::group([
    'prefix' => '/payments/espay/v1.0/transfer-va',
    'middleware' => [\App\Http\Middleware\EspaySnapCors::class],
    'excluded_middleware' => [
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
        \App\Http\Middleware\UserLocale::class,
        \App\Http\Middleware\DebugBar::class,
        \App\Http\Middleware\ZhAutoTranslateMiddleware::class,
    ],
], function () {
    Route::match(['post', 'options'], '/inquiry', [\App\Http\Controllers\EspayController::class, 'inquiry'])->name('espay.snap.inquiry');
    Route::match(['post', 'options'], '/payment', [\App\Http\Controllers\EspayController::class, 'payment'])->name('espay.snap.payment');
});

// Espay Payment Notification non-SNAP (VA Static Open, form-urlencoded + Hash-Based Signature)
Route::post('/payments/espay/notification', \App\Http\Controllers\EspayNotificationController::class)
    ->middleware(\App\Http\Middleware\EspaySnapCors::class)
    ->withoutMiddleware([
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
        \App\Http\Middleware\UserLocale::class,
        \App\Http\Middleware\DebugBar::class,
        \App\Http\Middleware\ZhAutoTranslateMiddleware::class,
    ])
    ->name('espay.notification');

// Espay SNAP Disbursement (withdraw): Transfer Confirmation (96) & Transfer Notification (97)
Route::group([
    'prefix' => '/payments/espay/v1.0/transfer',
    'middleware' => [\App\Http\Middleware\EspaySnapCors::class],
    'excluded_middleware' => [
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
        \App\Http\Middleware\UserLocale::class,
        \App\Http\Middleware\DebugBar::class,
        \App\Http\Middleware\ZhAutoTranslateMiddleware::class,
    ],
], function () {
    Route::match(['post', 'options'], '/confirmation', [\App\Http\Controllers\EspayDisbursementController::class, 'confirmation'])->name('espay.snap.transfer_confirmation');
    Route::match(['post', 'options'], '/notification', [\App\Http\Controllers\EspayDisbursementController::class, 'notification'])->name('espay.snap.transfer_notification');
});

// Espay checkout: pilih VA bank / QRIS. Dibuka sebagai popup (AJAX); tanpa JS tampil di dalam dashboard
Route::group(['prefix' => '/panel/financial/espay/{order}', 'middleware' => ['web.auth', 'panel', 'share']], function () {
    Route::get('/', [\App\Http\Controllers\EspayController::class, 'checkout'])->name('espay.checkout');
    Route::post('/pay', [\App\Http\Controllers\EspayController::class, 'pay'])->name('espay.pay');
    Route::get('/status', [\App\Http\Controllers\EspayController::class, 'status'])->name('espay.status');
});
// URL lama
Route::redirect('/payments/espay/checkout/{order}', '/panel/financial/espay/{order}');

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::group(['prefix' => 'my_api', 'namespace' => 'Api\Panel', 'middleware' => 'signed', 'as' => 'my_api.web.'], function () {
    Route::get('checkout/{user}', 'CartController@webCheckoutRender')->name('checkout');
    Route::get('/charge/{user}', 'PaymentsController@webChargeRender')->name('charge');
    Route::get('/subscribe/{user}/{subscribe}', 'SubscribesController@webPayRender')->name('subscribe');
    Route::get('/registration_packages/{user}/{package}', 'RegistrationPackagesController@webPayRender')->name('registration_packages');
});

Route::group(['prefix' => 'api_sessions'], function () {
    Route::get('/big_blue_button', ['uses' => 'Api\Panel\SessionController@BigBlueButton'])->name('big_blue_button');
    Route::get('/agora', ['uses' => 'Api\Panel\SessionController@agora'])->name('agora');
});

Route::get('/mobile-app', 'Web\MobileAppController@index')->middleware(['share'])->name('mobileAppRoute');
Route::get('/maintenance', 'Web\MaintenanceController@index')->middleware(['share'])->name('maintenanceRoute');
Route::get('/restriction', 'Web\RestrictionController@index')->middleware(['share'])->name('restrictionRoute');

Route::group(['prefix' => 'cookie-security'], function () {
    Route::post('/all', 'Web\CookieSecurityController@setAll');
    Route::post('/customize', 'Web\CookieSecurityController@setCustomize');
});










Route::group(['namespace' => 'Auth', 'middleware' => ['share', 'check_maintenance', 'check_restriction']], function () {
    Route::get('/login', 'LoginController@showLoginForm');
    Route::post('/login', 'LoginController@login');
    Route::get('/logout', 'LoginController@logout');
    Route::get('/register', 'RegisterController@showRegistrationForm');
    Route::post('/register', 'RegisterController@register');
    Route::post('/register/form-fields', 'RegisterController@getFormFieldsByUserType');
    Route::get('/verification', 'VerificationController@index');
    Route::post('/verification', 'VerificationController@confirmCode');
    Route::get('/verification/resend', 'VerificationController@resendCode');
    Route::get('/forget-password', 'ForgotPasswordController@showLinkRequestForm');
    Route::post('/forget-password', 'ForgotPasswordController@forgot');
    Route::get('reset-password/{token}', 'ResetPasswordController@showResetForm');
    Route::post('/reset-password', 'ResetPasswordController@updatePassword');
    Route::get('/google', 'SocialiteController@redirectToGoogle');
    Route::get('/google/callback', 'SocialiteController@handleGoogleCallback');
    Route::get('/facebook/redirect', 'SocialiteController@redirectToFacebook');
    Route::get('/facebook/callback', 'SocialiteController@handleFacebookCallback');
    Route::get('/reff/{code}', 'ReferralController@referral');
});

Route::group(['namespace' => 'Web', 'middleware' => ['check_mobile_app', 'impersonate', 'share', 'check_maintenance', 'check_restriction']], function () {
    Route::get('/stripe', function () {
        return view('web.default.cart.channels.stripe');
    });

    Route::fallback(function () {
        return view("errors.404", ['pageTitle' => trans('public.error_404_page_title')]);
    });

    // set Locale
    Route::post('/locale', 'LocaleController@setLocale')->name('appLocaleRoute');

    // set Locale
    Route::post('/set-currency', 'SetCurrencyController@setCurrency');

    Route::get('/', 'HomeController@index');

    Route::get('/getDefaultAvatar', 'DefaultAvatarController@make');

    Route::group(['prefix' => 'course'], function () {
        Route::get('/{slug}', 'WebinarController@course');
        Route::get('/{slug}/file/{file_id}/download', 'WebinarController@downloadFile');
        Route::get('/{slug}/file/{file_id}/showHtml', 'WebinarController@showHtmlFile');
        Route::get('/{slug}/lessons/{lesson_id}/read', 'WebinarController@getLesson');
        Route::post('/getFilePath', 'WebinarController@getFilePath');
        Route::get('/{slug}/file/{file_id}/play', 'WebinarController@playFile');
        Route::get('/{slug}/free', 'WebinarController@free');
        Route::get('/{slug}/applyOfflineClass', 'WebinarController@applyOfflineClass');
        Route::get('/{slug}/points/apply', 'WebinarController@buyWithPoint');
        Route::post('/{id}/report', 'WebinarController@reportWebinar');
        Route::post('/{id}/learningStatus', 'WebinarController@learningStatus');

        Route::group(['middleware' => 'web.auth'], function () {
            Route::get('/{slug}/installments', 'WebinarController@getInstallmentsByCourse');

            Route::post('/learning/itemInfo', 'LearningPageController@getItemInfo');
            Route::post('/learning/personalNotes', 'LearningPageController@personalNotes');
            Route::get('/learning/{slug}', 'LearningPageController@index');
            Route::get('/learning/{slug}/noticeboards', 'LearningPageController@noticeboards');
            Route::get('/assignment/{assignmentId}/download/{id}/attach', 'LearningPageController@downloadAssignment');
            Route::post('/assignment/{assignmentId}/history/{historyId}/message', 'AssignmentHistoryController@storeMessage');
            Route::post('/assignment/{assignmentId}/history/{historyId}/setGrade', 'AssignmentHistoryController@setGrade');
            Route::get('/assignment/{assignmentId}/history/{historyId}/message/{messageId}/downloadAttach', 'AssignmentHistoryController@downloadAttach');
            Route::post('/learning/uploadImageReport', 'LearningPageController@uploadImageReport');
            Route::get('/learning/finishOfflineClass/{sessionId}', 'LearningPageController@finishOfflineClass');

            Route::group(['prefix' => '/learning/{slug}/forum'], function () { // LearningPageForumTrait
                Route::get('/', 'LearningPageController@forum');
                Route::post('/store', 'LearningPageController@forumStoreNewQuestion');
                Route::get('/{forumId}/edit', 'LearningPageController@getForumForEdit');
                Route::post('/{forumId}/update', 'LearningPageController@updateForum');
                Route::post('/{forumId}/pinToggle', 'LearningPageController@forumPinToggle');
                Route::get('/{forumId}/downloadAttach', 'LearningPageController@forumDownloadAttach');

                Route::group(['prefix' => '/{forumId}/answers'], function () {
                    Route::get('/', 'LearningPageController@getForumAnswers');
                    Route::post('/', 'LearningPageController@storeForumAnswers');
                    Route::get('/{answerId}/edit', 'LearningPageController@answerEdit');
                    Route::post('/{answerId}/update', 'LearningPageController@answerUpdate');
                    Route::post('/{answerId}/{togglePinOrResolved}', 'LearningPageController@answerTogglePinOrResolved');
                });
            });

            Route::post('/direct-payment', 'WebinarController@directPayment');

            Route::group(['prefix' => 'personal-notes'], function () {
                Route::get('/{id}/download-attachment', 'CoursePersonalNotesController@downloadAttachment');
            });
        });
    });

    Route::group(['prefix' => 'certificate_validation'], function () {
        Route::get('/', 'CertificateValidationController@index');
        Route::post('/validate', 'CertificateValidationController@checkValidate');
    });


    Route::group(['prefix' => 'cart'], function () {
        Route::post('/store', 'CartManagerController@store');
        Route::get('/{id}/delete', 'CartManagerController@destroy');
    });

    Route::group(['middleware' => 'web.auth'], function () {

        Route::group(['prefix' => 'laravel-filemanager', 'middleware' => 'validate.file.upload'], function () {
            \UniSharp\LaravelFilemanager\Lfm::routes();
            Route::get('/domove', [\App\Http\Controllers\FileManager\ItemsController::class, 'doMove'])->middleware([
                \UniSharp\LaravelFilemanager\Middlewares\CreateDefaultFolder::class,
                \UniSharp\LaravelFilemanager\Middlewares\MultiUser::class,
            ]);
        });

        Route::group(['prefix' => 'reviews'], function () {
            Route::post('/store', 'WebinarReviewController@store');
            Route::post('/store-reply-comment', 'WebinarReviewController@storeReplyComment');
            Route::get('/{id}/delete', 'WebinarReviewController@destroy');
            Route::get('/{id}/delete-comment/{commentId}', 'WebinarReviewController@destroy');
        });

        Route::group(['prefix' => 'favorites'], function () {
            Route::get('{slug}/toggle', 'FavoriteController@toggle');
            Route::post('/{id}/update', 'FavoriteController@update');
            Route::get('/{id}/delete', 'FavoriteController@destroy');
        });

        Route::group(['prefix' => 'comments'], function () {
            Route::post('/store', 'CommentController@store');
            Route::post('/{id}/reply', 'CommentController@storeReply');
            Route::post('/{id}/update', 'CommentController@update');
            Route::post('/{id}/report', 'CommentController@report');
            Route::get('/{id}/delete', 'CommentController@destroy');
        });

        Route::group(['prefix' => 'cart'], function () {
            Route::get('/', 'CartController@index');

            Route::post('/coupon/validate', 'CartController@couponValidate')->middleware('throttle:30,1');
            Route::post('/checkout', 'CartController@checkout')->middleware('throttle:10,1')->name('checkout');
            Route::get('/checkout', 'CartController@checkoutView')->name('checkout.view');
        });

        Route::group(['prefix' => 'users'], function () {
            Route::get('/{id}/follow', 'UserController@followToggle');
        });

        Route::group(['prefix' => 'become-instructor'], function () {
            Route::get('/', 'BecomeInstructorController@index')->name('becomeInstructor');
            Route::get('/packages', 'BecomeInstructorController@packages')->name('becomeInstructorPackages');
            Route::get('/packages/{id}/checkHasInstallment', 'BecomeInstructorController@checkPackageHasInstallment');
            Route::get('/packages/{id}/installments', 'BecomeInstructorController@getInstallmentsByRegistrationPackage');
            Route::post('/', 'BecomeInstructorController@store');
            Route::post('/form-fields', 'BecomeInstructorController@getFormFieldsByUserType');
        });
    });

    Route::group(['prefix' => 'meetings'], function () {
        Route::post('/reserve', 'MeetingController@reserve');
    });

    Route::group(['prefix' => 'users'], function () {
        Route::get('/{id}/profile', 'UserController@profile');
        Route::post('/{id}/availableTimes', 'UserController@availableTimes');
        Route::post('/{id}/send-message', 'UserController@sendMessage');
    });

    Route::group(['prefix' => 'payments'], function () {
        Route::post('/payment-request', 'PaymentController@paymentRequest');
        Route::get('/verify/{gateway}', ['as' => 'payment_verify', 'uses' => 'PaymentController@paymentVerify']);
        Route::post('/verify/{gateway}', ['as' => 'payment_verify_post', 'uses' => 'PaymentController@paymentVerify']);
        Route::get('/status', 'PaymentController@payStatus');
        Route::get('/payku/callback/{id}', 'PaymentController@paykuPaymentVerify')->name('payku.result');
    });

    Route::group(['prefix' => 'subscribes'], function () {
        Route::get('/apply/{webinarSlug}', 'SubscribeController@apply');
        Route::get('/apply/bundle/{bundleSlug}', 'SubscribeController@bundleApply');
    });

    Route::group(['prefix' => 'search'], function () {
        Route::get('/suggestions', 'SearchController@suggestions');
        Route::get('/', 'SearchController@index');
    });

    Route::group(['prefix' => 'tags'], function () {
        Route::get('/{type}/{tag}', 'TagsController@index');
    });

    Route::group(['prefix' => 'categories'], function () {
        Route::get('/{categoryTitle}/{subCategoryTitle?}', 'CategoriesController@index');
        Route::get('/', 'CategoriesController@list');
    });

    Route::get('/classes', 'ClassesController@index');

    Route::get('/reward-courses', 'RewardCoursesController@index');

    Route::group(['prefix' => 'blog'], function () {
        Route::get('/', 'BlogController@index');
        Route::get('/categories/{category}', 'BlogController@index');
        Route::get('/{slug}', 'BlogController@show');
    });

    Route::group(['prefix' => 'programs'], function () {
        Route::get('/', 'MarketingProgramController@index');
        Route::get('/guest-register', 'MarketingProgramController@guestRegister');
        Route::get('/{id}/start', 'MarketingProgramController@start');
        Route::post('/track-contact', 'MarketingProgramController@trackContact');
    });

    Route::get('/kemitraan-lembaga', function () {
        return view('web.default.pages.kemitraan_lembaga');
    });
    Route::post('/kemitraan-lembaga', 'PartnershipRequestController@store');

    Route::group(['prefix' => 'contact'], function () {
        Route::get('/', 'ContactController@index');
        Route::post('/store', 'ContactController@store');
    });

    Route::group(['prefix' => 'instructors'], function () {
        Route::get('/', 'UserController@instructors');
    });

    Route::group(['prefix' => 'organizations'], function () {
        Route::get('/', 'PartnershipRequestController@indexPublic');
    });

    Route::group(['prefix' => 'load_more'], function () {
        Route::get('/{role}', 'UserController@handleInstructorsOrOrganizationsPage');
    });

    Route::group(['prefix' => 'pages'], function () {
        Route::get('/layanan', [\App\Http\Controllers\Web\LayananController::class, 'index']);
        Route::get('/refund-policy', function () {
            return view('web.default.pages.refund-policy', [
                'pageTitle'       => 'Kebijakan Pengembalian Dana - Edu Pavilion',
                'pageDescription' => 'Kebijakan refund Edu Pavilion: garansi 14 hari, proses transparan, pengembalian 5-7 hari kerja.',
            ]);
        });
        Route::get('/{link}', 'PagesController@index');
    });

    // Captcha
    Route::group(['prefix' => 'captcha'], function () {
        Route::post('create', function () {
            $response = ['status' => 'success', 'captcha_src' => captcha_src('flat')];

            return response()->json($response);
        });
        Route::get('{config?}', '\Mews\Captcha\CaptchaController@getCaptcha');
    });


    Route::post('/newsletters', 'UserController@makeNewsletter');

    Route::group(['prefix' => 'jobs'], function () {
        Route::get('/{methodName}', 'JobsController@index');
        Route::post('/{methodName}', 'JobsController@index');
    });

    Route::group(['prefix' => 'regions'], function () {
        Route::get('/provincesByCountry/{countryId}', 'RegionController@provincesByCountry');
        Route::get('/citiesByProvince/{provinceId}', 'RegionController@citiesByProvince');
        Route::get('/districtsByCity/{cityId}', 'RegionController@districtsByCity');
    });

    Route::redirect('/instructor-finders', '/instructor-finder');

    Route::group(['prefix' => 'instructor-finder'], function () {
        Route::get('/', 'InstructorFinderController@index');
        Route::get('/wizard', 'InstructorFinderController@wizard');
        Route::get('/{id}/download-cv', 'InstructorFinderController@downloadCv');
    });

    Route::group(['prefix' => 'products'], function () {
        Route::get('/', 'ProductController@searchLists');
        Route::get('/{slug}', 'ProductController@show');
        Route::post('/{slug}/points/apply', 'ProductController@buyWithPoint');

        Route::group(['prefix' => 'reviews'], function () {
            Route::post('/store', 'ProductReviewController@store');
            Route::post('/store-reply-comment', 'ProductReviewController@storeReplyComment');
            Route::get('/{id}/delete', 'ProductReviewController@destroy');
            Route::get('/{id}/delete-comment/{commentId}', 'ProductReviewController@destroy');
        });

        Route::group(['middleware' => 'web.auth'], function () {
            Route::get('/{slug}/installments', 'ProductController@getInstallmentsByProduct');
            Route::post('/direct-payment', 'ProductController@directPayment');
        });
    });

    Route::get('/reward-products', 'RewardProductsController@index');

    Route::group(['prefix' => 'bundles'], function () {
        Route::get('/{slug}', 'BundleController@index');
        Route::get('/{slug}/free', 'BundleController@free');

        Route::group(['middleware' => 'web.auth'], function () {
            Route::get('/{slug}/favorite', 'BundleController@favoriteToggle');
            Route::get('/{slug}/points/apply', 'BundleController@buyWithPoint');

            Route::group(['prefix' => 'reviews'], function () {
                Route::post('/store', 'BundleReviewController@store');
                Route::post('/store-reply-comment', 'BundleReviewController@storeReplyComment');
                Route::get('/{id}/delete', 'BundleReviewController@destroy');
                Route::get('/{id}/delete-comment/{commentId}', 'BundleReviewController@destroy');
            });

            Route::post('/direct-payment', 'BundleController@directPayment');
        });
    });

    Route::group(['prefix' => 'forums'], function () {
        Route::get('/', 'ForumController@index');
        Route::get('/create-topic', 'ForumController@createTopic');
        Route::post('/create-topic', 'ForumController@storeTopic');
        Route::get('/search', 'ForumController@search');

        Route::group(['prefix' => '/{slug}/topics'], function () {
            Route::get('/', 'ForumController@topics');
            Route::post('/{topic_slug}/likeToggle', 'ForumController@topicLikeToggle');
            Route::get('/{topic_slug}/edit', 'ForumController@topicEdit');
            Route::post('/{topic_slug}/edit', 'ForumController@topicUpdate');
            Route::post('/{topic_slug}/bookmark', 'ForumController@topicBookmarkToggle');
            Route::get('/{topic_slug}/downloadAttachment/{attachment_id}', 'ForumController@topicDownloadAttachment');

            Route::group(['prefix' => '/{topic_slug}/posts'], function () {
                Route::get('/', 'ForumController@posts');
                Route::post('/', 'ForumController@storePost');
                Route::post('/report', 'ForumController@storeTopicReport');
                Route::get('/{post_id}/edit', 'ForumController@postEdit');
                Route::post('/{post_id}/edit', 'ForumController@postUpdate');
                Route::post('/{post_id}/likeToggle', 'ForumController@postLikeToggle');
                Route::post('/{post_id}/un_pin', 'ForumController@postUnPin');
                Route::post('/{post_id}/pin', 'ForumController@postPin');
                Route::get('/{post_id}/downloadAttachment', 'ForumController@postDownloadAttachment');
            });
        });
    });


    Route::group(['prefix' => 'upcoming_courses'], function () {
        Route::get('/', 'UpcomingCoursesController@index');
        Route::get('{slug}', 'UpcomingCoursesController@show');
        Route::get('{slug}/toggleFollow', 'UpcomingCoursesController@toggleFollow');
        Route::get('{slug}/favorite', 'UpcomingCoursesController@favorite');
        Route::post('{id}/report', 'UpcomingCoursesController@report');
    });

    Route::group(['prefix' => 'installments'], function () {
        Route::group(['middleware' => 'web.auth'], function () {
            Route::get('/request_submitted', 'InstallmentsController@requestSubmitted');
            Route::get('/request_rejected', 'InstallmentsController@requestRejected');
            Route::get('/{id}', 'InstallmentsController@index');
            Route::post('/{id}/store', 'InstallmentsController@store');
        });
    });

    Route::group(['prefix' => 'waitlists'], function () {
        Route::post('/join', 'WaitlistController@store');
    });

    Route::group(['prefix' => 'gift'], function () {
        Route::group(['middleware' => 'web.auth'], function () {
            Route::get('/{item_type}/{item_slug}', 'GiftController@index');
            Route::post('/{item_type}/{item_slug}', 'GiftController@store');
        });
    });

    /* Forms */
    Route::get('/forms/{url}', 'FormsController@index');
    Route::post('/forms/{url}/store', 'FormsController@store');
});


