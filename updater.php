<?php
class PMPro_LNC_Bitcoinlightning_Updater {

	private $file;
	private $plugin;
	private $basename;
	private $active;
	private $username;
	private $repository;
	private $authorize_token;
	private $github_response;

	public function __construct( $file ) {

		$this->file = $file;

		add_action( 'admin_init', array( $this, 'set_plugin_properties' ) );

		return $this;
	}

	public function set_plugin_properties() {
		$this->plugin	= get_plugin_data( $this->file );
		$this->basename = plugin_basename( $this->file );
		$this->active	= is_plugin_active( $this->basename );
	}

	public function set_username( $username ) {
		$this->username = $username;
	}

	public function set_repository( $repository ) {
		$this->repository = $repository;
	}

	public function authorize( $token ) {
		$this->authorize_token = $token;
	}

	private function get_repository_info() {
	    if ( is_null( $this->github_response ) ) { // Hebben we een reactie?
		$args = array();
	        $request_uri = sprintf( 'https://api.github.com/repos/%s/%s/releases', $this->username, $this->repository ); // Bouw URI op
		$args = array();

	        if( $this->authorize_token ) { // Is er een toegangstoken?
		          $args['headers']['Authorization'] = "bearer {$this->authorize_token}"; // Stel de headers in
	        }

	        $response = json_decode( wp_remote_retrieve_body( wp_remote_get( $request_uri, $args ) ), true ); // Haal JSON op en analyseer het

	        if( is_array( $response ) ) { // Als het een array is
	            $response = current( $response ); // Haal het eerste item op
	        }

	        $this->github_response = $response; // Stel het in als onze eigenschap
	    }
	}

	public function initialize() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'modify_transient' ), 10, 1 );
		add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3);
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

		// Voeg Autorisatietoken toe aan download_package
		add_filter( 'upgrader_pre_download',
			function() {
				add_filter( 'http_request_args', [ $this, 'download_package' ], 15, 2 );
				return false; // Standaard retourwaarde voor upgrader_pre_download filter.
			}
		);
	}

	public function modify_transient( $transient ) {

		if( property_exists( $transient, 'checked') ) { // Controleer of de tijdelijke waarde een gecontroleerde eigenschap heeft

			if( $checked = $transient->checked ) { // Heeft WordPress gecontroleerd op updates?

				$this->get_repository_info(); // Haal de repo-info op
				$out_of_date = version_compare( $this->github_response['tag_name'], $checked[ $this->basename ] ); // Controleer of we verouderd zijn

				if( $out_of_date ) {

					$new_files = $this->github_response['zipball_url']; // Haal de ZIP op

					$slug = current( explode('/', $this->basename ) ); // Maak een geldige slug aan

					$plugin = array( // stel onze plugin-info in
						'url' => $this->plugin["PluginURI"],
						'slug' => $slug,
						'package' => $new_files,
						'new_version' => $this->github_response['tag_name']
					);

					$transient->response[$this->basename] = (object) $plugin; // Retourneer het als reactie
				}
			}
		}

		return $transient; // Retourneer gefilterde tijdelijke waarde
	}

	public function plugin_popup( $result, $action, $args ) {

		if( ! empty( $args->slug ) ) { // Als er een slug is

			if( $args->slug == current( explode( '/' , $this->basename ) ) ) { // En het is onze slug

				$this->get_repository_info(); // Haal onze repo-info op

				// Stel het in als een array
				$plugin = array(
					'name'				=> $this->plugin["Name"],
					'slug'				=> $this->basename,
					'version'			=> $this->github_response['tag_name'],
					'author'			=> $this->plugin["AuthorName"],
					'author_profile'	=> $this->plugin["AuthorURI"],
					'last_updated'		=> $this->github_response['published_at'],
					'homepage'			=> $this->plugin["PluginURI"],
					'short_description' => $this->plugin["Description"],
					'sections'			=> array(
						'Description'	=> $this->plugin["Description"],
						'Updates'		=> $this->github_response['body'],
					),
					'download_link'		=> $this->github_response['zipball_url']
				);

				return (object) $plugin; // Retourneer de gegevens
			}

		}
		return $result; // Anders retourneer standaardwaarde
	}

	public function download_package( $args, $url ) {

		if ( null !== $args['filename'] ) {
			if( $this->authorize_token ) {
				$args = array_merge( $args, array( "headers" => array( "Authorization" => "token {$this->authorize_token}" ) ) );
			}
		}

		remove_filter( 'http_request_args', [ $this, 'download_package' ] );

		return $args;
	}

	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem; // Haal het globale FS-object op

		$install_directory = plugin_dir_path( $this->file ); // Onze plugin-map
		$wp_filesystem->move( $result['destination'], $install_directory ); // Verplaats bestanden naar de plugin-map
		$result['destination'] = $install_directory; // Stel de bestemming in voor de rest van de stack

		if ( $this->active ) { // Als het actief was
			activate_plugin( $this->basename ); // Activeer opnieuw
		}

		return $result;
	}
}
