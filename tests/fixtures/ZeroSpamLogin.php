<?php
// phpcs:ignoreFile

namespace {
	if ( ! class_exists( 'WP_Hook' ) ) {
		class WP_Hook {
			public array $callbacks = array();
		}
	}

	if ( ! class_exists( 'WP_User' ) ) {
		class WP_User {
			public int $ID;
			public string $user_login;

			public function __construct( $id = 1, $user_login = 'test-user' ) {
				$this->ID         = (int) $id;
				$this->user_login = (string) $user_login;
			}
		}
	}
}

namespace ZeroSpam\Modules\Login {
	final class Login {
		public int $calls = 0;

		public function process_form( $user, $password ) {
			++$this->calls;
			return new \WP_Error( 'failed_zerospam', 'form token missing' );
		}
	}
}
