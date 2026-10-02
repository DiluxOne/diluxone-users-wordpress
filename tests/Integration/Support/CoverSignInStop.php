<?php
/**
 * Thrown by the stand-in for wp-login.php's login_footer(), so a screen that
 * ends in `exit` right after it stops there instead, with its page drawn.
 */

namespace Tests\Integration\Support;

final class CoverSignInStop extends \RuntimeException {}
