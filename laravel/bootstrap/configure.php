<?php
/**
 * Shared Laravel middleware and internal routing configuration.
 *
 * @package Digitalogic
 */

use Digitalogic\Laravel\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure( basePath: dirname( __DIR__ ) )
	->withRouting( api: __DIR__ . '/../routes/internal.php', apiPrefix: '_digitalogic' )
	->withMiddleware(
		static function ( Middleware $middleware ): void {
			// WordPress owns sessions and capabilities; no Laravel session middleware.
			$middleware->use( array() );
			$middleware->group( 'api', array() );
			// Native quote fields are typed JSON strings, including optional empty
			// values. Laravel's default converter otherwise changes them to null
			// before the bounded checkout validator sees the request. Keep every
			// other route and the existing trimming/security middleware unchanged.
			$middleware->convertEmptyStringsToNull(
				array(
					static fn ( \Illuminate\Http\Request $request ): bool => $request->isJson() && $request->is( '_digitalogic/mobile/checkout/quote' ),
				)
			);
		}
	)
	->withExceptions()
	->create();
