<?php
/**
 * Adapter for the WordPress AI plugin.
 *
 * @package Soderlind\Plugin\LoupeSemanticSearch
 */

namespace Soderlind\Plugin\LoupeSemanticSearch;

use WordPress\AI\Embeddings\Sync\Embedding_Sync;
use WordPress\AI\Embeddings\Sync\Object_Processor;
use WordPress\AI\Embeddings\Sync\Post_Source;
use WordPress\AI\Embeddings\Text_Chunker;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\EmbeddingBuilder;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

defined( 'ABSPATH' ) || exit;

/**
 * Every call into the `ai` plugin goes through this class, so a change in its API
 * touches one file.
 */
class Ai_Adapter {

	public const CONSUMER_ID = 'loupe-semantic-search';

	/**
	 * Transient prefix for the embedding model list.
	 */
	public const MODELS_TRANSIENT = 'loupe_semantic_models_';

	/**
	 * Whether the `ai` plugin with embedding sync (1.5+) is loaded.
	 */
	public function is_available(): bool {
		return function_exists( 'WordPress\\AI\\supports_embedding_generation' )
			&& \WordPress\AI\supports_embedding_generation()
			&& class_exists( Embedding_Sync::class )
			&& class_exists( Post_Source::class )
			&& class_exists( Object_Processor::class )
			&& class_exists( Text_Chunker::class );
	}

	/**
	 * Embedding models offered by connected providers.
	 *
	 * Listing models is an HTTP request per provider. The AI client caches it in the
	 * object cache, which does not outlive the request without a persistent object
	 * cache, so the list is kept in a transient for 12 hours. The transient key
	 * changes when a connector is added, removed, connected or disconnected.
	 *
	 * @param bool $refresh Ask the providers again instead of using the saved list.
	 * @return list<array{provider:string,provider_name:string,model:string,model_name:string}>
	 */
	public function embedding_models( bool $refresh = false ): array {
		if ( ! $this->is_available() || ! function_exists( 'WordPress\\AI\\get_ai_connectors' ) ) {
			return [];
		}

		$key = self::MODELS_TRANSIENT . md5( (string) wp_json_encode( $this->connector_state() ) );
		if ( ! $refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$models = $this->fetch_embedding_models();
		if ( ! empty( $models ) ) {
			set_transient( $key, $models, 12 * HOUR_IN_SECONDS );
		}
		return $models;
	}

	/**
	 * Active connectors and whether each has credentials, without network requests.
	 *
	 * @return array<string,bool>
	 */
	protected function connector_state(): array {
		$state = [];
		foreach ( array_keys( \WordPress\AI\get_ai_connectors() ) as $provider_id ) {
			$state[ (string) $provider_id ] = function_exists( 'WordPress\\AI\\has_connector_authentication' )
				&& \WordPress\AI\has_connector_authentication( (string) $provider_id );
		}
		ksort( $state );
		return $state;
	}

	/**
	 * Ask each connected provider for its embedding models.
	 *
	 * @return list<array{provider:string,provider_name:string,model:string,model_name:string}>
	 */
	protected function fetch_embedding_models(): array {
		$registry     = AiClient::defaultRegistry();
		$requirements = new ModelRequirements( [ CapabilityEnum::embeddingGeneration() ], [] );
		$models       = [];

		foreach ( array_keys( \WordPress\AI\get_ai_connectors() ) as $provider_id ) {
			try {
				$found = $registry->findProviderModelsMetadataForSupport( (string) $provider_id, $requirements );
				if ( empty( $found ) ) {
					continue;
				}
				$provider_class = $registry->getProviderClassName( (string) $provider_id );
				$provider_name  = $provider_class::metadata()->getName();
				foreach ( $found as $model ) {
					$models[] = [
						'provider'      => (string) $provider_id,
						'provider_name' => (string) $provider_name,
						'model'         => (string) $model->getId(),
						'model_name'    => (string) $model->getName(),
					];
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $models;
	}

	/**
	 * Embed one text with a request timeout.
	 *
	 * @param string   $text       Text to embed.
	 * @param string   $provider   Provider ID.
	 * @param string   $model      Model ID.
	 * @param int|null $dimensions Requested dimensions, or null for the model default.
	 * @param float    $timeout    Request timeout in seconds.
	 * @return list<float>|\WP_Error
	 */
	public function embed( string $text, string $provider, string $model, ?int $dimensions, float $timeout ) {
		if ( ! $this->is_available() || ! class_exists( EmbeddingBuilder::class ) ) {
			return new \WP_Error( 'loupe_semantic_unavailable', __( 'Embedding generation is not available.', 'loupe-semantic-search' ) );
		}

		try {
			$builder = ( new EmbeddingBuilder( AiClient::defaultRegistry(), [ $text ] ) )->usingProviderModel( $provider, $model );
			if ( null !== $dimensions ) {
				$builder = $builder->usingDimensions( $dimensions );
			}
			$options = new RequestOptions();
			$options->setTimeout( $timeout );
			$options->setConnectTimeout( min( $timeout, 1.0 ) );
			$builder = $builder->usingRequestOptions( $options );

			$embeddings = $builder->generateEmbeddingResult()->getEmbeddings();
			if ( empty( $embeddings ) ) {
				return new \WP_Error( 'loupe_semantic_empty_embedding', __( 'The provider returned no embedding.', 'loupe-semantic-search' ) );
			}
			return array_map( 'floatval', array_values( $embeddings[0]->getValues() ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'loupe_semantic_embedding_failed', $e->getMessage() );
		}
	}

	/**
	 * Register our embedding consumer. Must run before `init` priority 20.
	 *
	 * @param array{provider:string,model:string,dimensions:int|null} $target     Embedding target.
	 * @param list<string>                                            $post_types Post types to embed.
	 */
	public function register_consumer( array $target, array $post_types ): bool {
		if ( ! $this->is_available() || empty( $post_types ) ) {
			return false;
		}
		$args = [
			'provider' => $target['provider'],
			'model'    => $target['model'],
			'objects'  => [ 'post' => array_values( $post_types ) ],
		];
		if ( null !== $target['dimensions'] ) {
			$args['dimensions'] = $target['dimensions'];
		}
		return \WordPress\AI\register_embedding_consumer( self::CONSUMER_ID, $args );
	}

	/**
	 * Whether our consumer is registered.
	 */
	public function is_registered(): bool {
		return $this->is_available() && null !== Embedding_Sync::instance()->get_registry()->get_consumer( self::CONSUMER_ID );
	}

	/**
	 * Sync status for our consumer.
	 *
	 * @return array<string,mixed>|null
	 */
	public function status(): ?array {
		return $this->is_registered() ? Embedding_Sync::get_status( self::CONSUMER_ID ) : null;
	}

	/**
	 * Start a backfill of our consumer.
	 */
	public function start_backfill(): bool {
		return $this->is_registered() && Embedding_Sync::start_backfill( self::CONSUMER_ID );
	}

	/**
	 * Cancel our backfill.
	 */
	public function cancel_backfill(): bool {
		return $this->is_registered() && Embedding_Sync::cancel_backfill( self::CONSUMER_ID );
	}

	/**
	 * Reset our backfill so the next one starts from the beginning.
	 */
	public function reset_backfill(): bool {
		return $this->is_registered() && Embedding_Sync::reset_backfill( self::CONSUMER_ID );
	}

	/**
	 * Whether another consumer embeds posts of a subtype.
	 *
	 * @param string $subtype Post type.
	 */
	public function other_consumer_covers( string $subtype ): bool {
		foreach ( $this->other_consumers() as $consumer ) {
			if ( in_array( $subtype, (array) ( $consumer['objects']['post'] ?? [] ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether another consumer uses the same provider and model.
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 */
	public function other_consumer_uses( string $provider, string $model ): bool {
		foreach ( $this->other_consumers() as $consumer ) {
			if ( ( $consumer['provider'] ?? '' ) === $provider && ( $consumer['model'] ?? '' ) === $model ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether our consumer covers a post type.
	 *
	 * @param string $subtype Post type.
	 */
	public function covers( string $subtype ): bool {
		if ( ! $this->is_available() ) {
			return false;
		}
		$consumer = Embedding_Sync::instance()->get_registry()->get_consumer( self::CONSUMER_ID );
		return null !== $consumer && in_array( $subtype, (array) ( $consumer['objects']['post'] ?? [] ), true );
	}

	/**
	 * Delete every vector of a model.
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @return int Rows deleted.
	 */
	public function prune( string $provider, string $model ): int {
		return $this->is_available() ? Embedding_Sync::prune_target( $provider, $model ) : 0;
	}

	/**
	 * Iterate over stored post vectors of a model.
	 *
	 * @param string $provider Provider ID.
	 * @param string $model    Model ID.
	 * @return iterable<array{post_id:int,post_type:string,chunk_index:int,vector:list<float>,hash:string}>
	 */
	public function iterate_vectors( string $provider, string $model ): iterable {
		if ( ! $this->is_available() ) {
			return;
		}
		$repository = Embedding_Sync::instance()->get_repository();
		foreach ( $repository->iterate( $provider, $model, Post_Source::OBJECT_TYPE, 200 ) as $record ) {
			yield [
				'post_id'     => (int) $record->get_object_id(),
				'post_type'   => (string) $record->get_object_subtype(),
				'chunk_index' => (int) $record->get_chunk_index(),
				'vector'      => $record->get_vector(),
				'hash'        => (string) $record->get_content_hash(),
			];
		}
	}

	/**
	 * The text the `ai` plugin embeds for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function post_text( int $post_id ): string {
		return ( new Post_Source() )->get_text( $post_id );
	}

	/**
	 * The content hash the `ai` plugin stores for a text.
	 *
	 * @param string   $text       Full post text.
	 * @param int|null $dimensions Requested dimensions.
	 */
	public function hash_text( string $text, ?int $dimensions ): string {
		return Object_Processor::hash_text( $text, $this->max_chunks(), $dimensions );
	}

	/**
	 * Split a text into the chunks the `ai` plugin embeds.
	 *
	 * @param string $text Full post text.
	 * @return list<string>
	 */
	public function chunks( string $text ): array {
		return array_values( array_slice( ( new Text_Chunker() )->chunk( $text ), 0, $this->max_chunks() ) );
	}

	/**
	 * Chunk cap in force, as the `ai` plugin computes it.
	 */
	private function max_chunks(): int {
		/** This filter is documented in the ai plugin's Object_Processor. */
		return max( 1, (int) apply_filters( 'wpai_embedding_sync_max_chunks', Object_Processor::DEFAULT_MAX_CHUNKS ) );
	}

	/**
	 * Registered consumers other than ours.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function other_consumers(): array {
		if ( ! $this->is_available() ) {
			return [];
		}
		$registry  = Embedding_Sync::instance()->get_registry();
		$consumers = [];
		foreach ( $registry->get_consumer_ids() as $id ) {
			if ( self::CONSUMER_ID !== $id ) {
				$consumers[ $id ] = (array) $registry->get_consumer( $id );
			}
		}
		return $consumers;
	}
}
