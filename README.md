RealEx PHP Library - Remote Integration method
==============================
By Tom Holder - [http://www.simpleweb.co.uk](http://www.simpleweb.co.uk)

Overview
--------
This is a PHP library designed for integration in to Zend Framework based projects (would be easy to adapt to work outside).

To Do
-----

Better examples.

Add some automated tests.

Usage
-----

Adding card:

    $realex = new Realex_Eft();
    $payer = new Realex_Payer();
    $payer->ref = '1234'; //This is a reference you set for the card, you'd store this against the db for the user/card.

    $rexCard = new Realex_Card($payer);
    $rexCard->ref = $cc->realExRef;
    $rexCard->number = '4444333322221111';
    $rexCard->holder = 'MR TOM HOLDER';
    $rexCard->expiry = '0412';
    $rexCard->type = 'MC;

    $response = $realex->NewCard($rexCard);

    if ($response->result == 0) {
        echo 'woo!';
    }

Need example for raising payment but in summary you use RaisePayment method passing in a Realex_Payment object that has been initiated with a payer and card.

License
-------
This plugin is licensed under both the GPL and MIT licenses. Choose which ever one suits your project best.