<?php
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Yaml\Yaml;
use \WP_CLI\Utils;

/**
 * Manage security issues and settings.
 *
 * ## OPTIONS
 *
 * [--verbose]
 * : Increase verbosity.
 *
 * ## EXAMPLES
 *
 *     wp secure filecheck
 * 	   wp secure perms
 *
 */

class Secure_Command extends WP_CLI_Command {
	private $workingdir = '/tmp/wp-test';
	private $fs = false; // symfony filesytem object
	private $skipfiles = array('wp-config.php');
	private $report = array();
	private $fix = true;
	private $verbose = true;
	private $perms = array();
	private $permprofile = 'default';

	public function __construct()
	{
		// For some reason symfony's autoloader doesn't isn't properly configured
		// @todo figure out why simply use Symfony\Component\Filesystem\Filesystem\Filesystem doesn't work
		if( !class_exists('Symfony\Component\Filesystem\Filesystem\Filesystem') ) {
			include_once WP_CLI_ROOT."/vendor/symfony/filesystem/Symfony/Component/Filesystem/Filesystem.php";
		}
		$this->fs = new Symfony\Component\Filesystem\Filesystem\Filesystem();
	}
	/**
	 * Compares installation to core versions of file and fixes those that have been changed
	 */
	public function filecheck( $args, $assoc_args)
	{
		$this->fix = @$assoc_args['fix'];
		
		// Download core files 
		if ( false === $this->workingdir ) {
			$this->build_core_files();
			$this->build_plugin_files();
		}
		$this->build_plugin_files();exit();

		// iterates over the wp core src and compares to current state		
		$this->iterate_dir($this->workingdir,"check_file");		

		$start_path = rtrim( ABSPATH, "/" );
		if( @$assoc_args['directory'] ) {
			$start_path = "$start_path/{$assoc_args['directory']}";
		}
		$this->iterate_dir($start_path,"check_install");
		
		// if --fix is specified changed files will be returned to the core state
		if ( $this->fix ) {
			$this->update_changed();
		}
		
		WP_CLI::success("Check completed");
	}
	
	public function perms( $args, $assoc_args )
	{
			if ( isset($assoc_args['profile']) ) {
				$this->permprofile = 'custom';
			}
			$this->load_perms_options();
			try {
				$start_path = rtrim( ABSPATH, "/" );
				if( @$assoc_args['directory'] ) {
					$start_path = "$start_path/{$assoc_args['directory']}";
				}

				$this->iterate_dir( $start_path, 'checkPerms' );
			} catch (Exception $e) {
				WP_CLI::error($e->getMessage());
			}
			
	}
	
	public function load_perms_options()
	{
		$this->perms = Yaml::parse(file_get_contents(__DIR__."/fileperms.yml"));
		if( !is_array($this->perms['default']) ) {
			$this->perms['default'] = array(
				'file'=>'0755',
				'directory'=>'0755'
			);
		}
	}
	
	public function build_core_files() 
	{
		global $wp_version;
		
		if ( !is_writable("/tmp") ) WP_CLI::error("The tmp directory is not writable.");
		if ( ! $locale = getenv(WP_CLI_LOCALE) ) {
			$locale = 'en_US';
		}
		$url = WP_CLI\Utils\get_wp_download_url($wp_version,$locale,"tar.gz");
		$headers = array('Accept' => 'application/json');
		$temp = sys_get_temp_dir() . '/' . uniqid('wp_') . '.tar.gz';
		$options = array(
			'timeout' => 600,  // 10 minutes ought to be enough for everybody
			'filename' => $temp
		);
				
		WP_CLI::log( sprintf("Fetching %s\nSaving to %s", $url, $temp) );
		WP_CLI\Utils\make_request( 'GET', $url, $headers, $options );
		
		WP_CLI::log( sprintf("Extracting %s ...", $temp ));
		$this->workingdir = WP_CLI\Utils\extract_archive( $temp, basename( $temp, '.tar.gz' ) );
	}
	
	public function build_plugin_files()
	{
		require_once ABSPATH.'wp-admin/includes/plugin.php';
		require_once ABSPATH.'wp-admin/includes/plugin-install.php';
		$target_dir = $this->workingdir."/wordpress/wp-content/plugins";
		
		foreach( get_plugins() as $file => $info ) {	
			$plugin = explode('/',$file);
			$slug = $plugin[0];
			$version = trim($info['Version']);

			// skip plugins we've already got
			if ( file_exists( $target_dir.$slug ) and is_dir( $target_dir.$slug ) ) 
				continue;
			
			$api = plugins_api( 'plugin_information', array( 'slug' => $slug ) );
			
			if( $api->version === $version ) {
				$download_url = $api->download_link;
			} else {
				$download_url = "https://downloads.wordpress.org/plugin/$slug.$version.zip";
			}
			
			$headers = array('Accept' => 'application/json');
			$target_file = str_replace( 'https://downloads.wordpress.org/plugin' , $target_dir, $download_url);
			$options = array(
				'timeout' => 600,  // 10 minutes ought to be enough for everybody
				'filename' => $target_file,
			);
			
			WP_CLI::log( sprintf("Fetching %s\nSaving to %s", $url, $target_file) );
			WP_CLI\Utils\make_request( 'GET', $download_url, $headers, $options );
			
			WP_CLI::log( sprintf("Extracting %s ...", $target_file ));
			WP_CLI\Utils\extract_archive( $target_file, basename($target_file,".zip") );

			
		}
	}
	
	public function iterate_dir( $dir, $callback )
	{
		$src = new DirectoryIterator($dir);
		foreach( $src as $file ) {
			if( $file->isDot() ) continue;
			if( in_array($file->getFilename(),$this->skipfiles) ) continue;
			$path = $file->getPathname();
			if( $file->isDir() ) { 
				call_user_func_array( array($this, $callback) , array($path) );
				if ( is_readable($file->getPathname()) ) {
					$this->iterate_dir( $file->getPathname(), $callback );
				} else {
					WP_CLI::error(sprintf("Coudn't open %s", $path));
				}
			}
			call_user_func_array( array($this, $callback) ,array($path) );
		}
	}
	
	public function check_install( $path ) 
	{
		if ( $this->isWPContent( $path ) ) return;

		$corepath = str_replace( rtrim(ABSPATH,'/'), $this->workingdir, $path );
		if ( $this->fs->exists($path) AND !$this->fs->exists($corepath) ) {
			WP_CLI::warning( sprintf("Non-core path found - %s", str_replace( rtrim(ABSPATH,'/'), '', $path ) ) );
		}
	}	
	
	public function findDirs( $base ) 
	{
		$src = new DirectoryIterator($dir);
		$dirs = array();
		foreach( $src as $path ) {
			if( $path->isDot() ) continue;
			if( !$path->isDir() ) continue;
			$dirs[] = $path->getPathname();
		}
		return $dirs;
	}
	
	private function check_file( $path )
	{
		if ( is_dir($path) ) return false;
		$matchfile = sprintf( "%s/%s", rtrim(ABSPATH,'/') , ltrim( str_replace( $this->workingdir,"",$path ), '/' ) );
		if( !file_exists($matchfile) ) {
			$this->report( $path, 'missing');
			return false;
		}
		
		if( md5( file_get_contents($matchfile) ) === md5( file_get_contents($path) ) ) {
	
			if ( $this->verbose )
				$this->report( $path, 'matched' );
				
			return true;
		}
		
		$this->report($path,'changed');
		
		return false;
	}
	
	private function checkPerms( $path )
	{

		if ( !file_exists( $path ) ) {
			return false;
		}
		
		$perm = $this->perms['default']['file'];
		if ( is_dir( $path ) ) {
			$perm = $this->perms['default']['directory'];
		}
	
		if ( $this->permprofile ) {
			foreach( $this->perms[$this->permprofile] as $pattern => $custom_perm ) {
				if ( preg_match( "#$pattern#", $path ) ) {
					$perm = $custom_perm;
				}
			} 
		}

		$this->fs->chmod($path, octdec($perm));
		if ( $this->verbose ) {
			WP_CLI::log( sprintf( "Chmod %s -> %s", $perm, $path ) );
		}
	}

	private function update_changed() 
	{
		foreach( $this->report as $path => $info ) {
			if( $info['changed'] === 'n' AND $info['missing'] === 'n' ) continue;
			$this->syncFile($path,true);
			$this->report( $path, 'updated' );
		}
	}
	
	private function syncFile($file, $strict = false )
	{
		$wppath = str_replace($this->workingdir,rtrim( ABSPATH, "/" ), $file);			
		$tmppath = $file;
		if( $strict ) {
			if( file_exists( $tmppath ) AND !file_exists($wppath) ) {
				if( is_dir(dirname($wppath) ) ) {
					$this->fs->mkdir( dirname($wppath) );
				}
				$this->fs->copy($tmppath, $wppath ,true);		
			}
		}
		WP_CLI::log( sprintf("copying %s -> %s", $tmppath,$wppath));
		if ( file_exists( $tmppath ) AND file_exists($wppath) ) {
			$this->fs->copy($tmppath,$wppath,true);
		}
	}
	
	private function isPlugin( $path ) 
	{
		$file = str_replace(ABSPATH,"",$path);
		return preg_match("#^[^\/]+/plugins\/.*#", $file );
	}

	private function isTheme( $path ) 
	{
		$file = str_replace(ABSPATH,"",$path);
		return preg_match("#^[^\/]+/theme\/.*#", $file );
	}
	
	private function isWPContent( $path ) {
		$file = str_replace(rtrim(ABSPATH,"/"),"",$path);
		if ( strstr( $path, WP_CONTENT_DIR ) ) {
			return true;
		}
		return false;
	}
	
	public function report( $path, $status )
	{
		if ( !isset( $this->report[$path] )) {
			$default = array( 
				'changed' => 'n',
				'updated' => 'n',
				'missing' => 'n',
				'matched' => 'n',
			);
			$this->report[$path] = array_merge( $default, array( $status => 'y' ) );
		} else {
			$this->report[$path][$status] = 'y';
		}
	}
	
	public function printReport()
	{
		$table = new Table( new  ConsoleOutput() );
		$table->setHeaders(array('file','changed','missing','fixed') );
		foreach( $this->report as $path => $info ) {
			$table->addRow( array( str_replace($this->workingdir,'',$path),$info['changed'],$info['missing'],$info['updated']) );
		}
		$table->render();
	}
	
	public function __destruct() 
	{
		//$this->fs->remove( array( dirname( $this->workingdir ) ) );
	}

}

WP_CLI::add_command( 'secure', 'Secure_Command' );

