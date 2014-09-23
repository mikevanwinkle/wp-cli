<?php
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
/** 
 * Class to inspect and manipulate vars
 *
 * ## EXAMPLES
 *
 *     wp vars constants
 */

class Vars_Command extends WP_CLI_Command {
	public $format = "table";
	public $like = false; // regex for a specific var
	public $hook = false; // run on a specific hook
	public $vars = array();
		
	public function constants( $args, $assoc_args ) {
		$this->like = @$assoc_args['like'] ? $assoc_args['like'] : false;
		$this->hook = @$assoc_args['hook'] ? $assoc_args['hook'] : false;
		
		$vars = get_defined_constants();
		foreach( $vars as $key => $value ) {
			if( $this->like and !preg_match("#".$this->like."#", $key) ) {
				continue;
			}
			$this->vars[$key] = $value;
		}
		$this->printOutput( $this->format );
	}

	public function globals( $args, $assoc_args ) {
		$this->like = @$assoc_args['like'] ? $assoc_args['like'] : false;
		$this->hook = @$assoc_args['hook'] ? $assoc_args['hook'] : false;
		
		$vars = $GLOBALS;
		foreach( $vars as $key => $value ) {
			if( $this->like and !preg_match("#".$this->like."#", $key) ) {
				continue;
			}
			if( is_object($value) ) $value = var_export($value);
			$this->vars[$key] = $value;
		}
		$this->printOutput( $this->format );
	}
	
	public function printOutput( $format )
	{
		switch( $format ) {
			case "table":
			case "default":
				$table = new Table( new  ConsoleOutput() );
				$table->setHeaders( array("Name","Value") );
				foreach( $this->vars as $key => $value ) {
					$table->addRow( array( $key, (string) $value) );
				}
				$table->render();
				break;
		}
	}
}
WP_CLI::add_command( 'vars', 'Vars_Command' );
