<?php
namespace WP_CLI;

use Symfony\Component\Filesystem\Filesystem;

class Files {

	public function __construct() {
		return new Filesystem();
	}

}
?>