<?php
namespace SocialAuth\View\Helper;

use Zend\View\Helper\AbstractHelper;

class SocialSignInButton extends AbstractHelper
{
    public function __invoke($provider, $redirect = false)
    {
        $redirectArg = $redirect ? '?redirect=' . $redirect : '';
        echo
            '<a class="btn btn-tempelle btn-lg facebook" href="'
            . $this->view->url('social-auth-user/login/provider', array('provider' => $provider))
            . $redirectArg . '"><i class="fa fa-facebook"></i> Sign in using ' . ucfirst($provider) . '</a>';
    }
}
