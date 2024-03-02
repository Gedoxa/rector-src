<?php

if (isset($_POST['alias'])) {
    $alias = $_POST['alias'];
    $alias['id'] = $aliasold['id'];
    $alias['login'] = trim($alias['login']);
    $alias['accounts'] = $SESSION->get('aliasaccounts');
    $alias['mailforwards'] = $SESSION->get('aliasmailforwards');

    if (!isset($alias['domainalias'])) {
        if ($alias['login'] == '') {
            $error['login'] = trans('You have to specify alias name!');
        } elseif (!preg_match('/^[a-z0-9._-]+$/', $alias['login'])) {
                $error['login'] = trans('Login contains forbidden characters!');
        }
    } else {
        $alias['login'] == '';
    }
}
?>
-----
<?php

namespace Rector\Tests\DeadCode\Rector\Expression\RemoveDeadStmtRector\Fixture;

// what is expected code?
// should remain the same? delete part below ----- (included)

?>
