<?php
/**
 * Safe HTML attribute collection.
 *
 * @package Emulsify
 */

namespace Emulsify\Theme\Support;

/**
 * Small AttributeBag implementation for Twig helper output.
 */
final class AttributeBag implements \Stringable {

	/**
	 * Attribute values keyed by attribute name.
	 *
	 * @var array<string, mixed>
	 */
	private $attributes = array();

	/**
	 * Constructor.
	 *
	 * @param mixed $attributes Initial attributes.
	 */
	public function __construct( $attributes = array() ) {
		$this->merge( $attributes );
	}

	/**
	 * Creates a cloned attribute bag.
	 *
	 * @return self Cloned attribute bag.
	 */
	public function copy(): self {
		return new self( $this->toArray() );
	}

	/**
	 * Appends class tokens.
	 *
	 * @param mixed $value Class value.
	 * @return self Current instance.
	 */
	public function addClass( $value ): self {
		$tokens = self::classTokensFromValue( $value );

		if ( empty( $tokens ) ) {
			return $this;
		}

		$existing                  = $this->attributes['class'] ?? array();
		$this->attributes['class'] = self::uniqueList( array_merge( (array) $existing, $tokens ) );

		return $this;
	}

	/**
	 * Snake-case alias for PHP callers.
	 *
	 * @param mixed $value Class value.
	 * @return self Current instance.
	 */
	public function add_class( $value ): self {
		return $this->addClass( $value );
	}

	/**
	 * Gets the normalized class list.
	 *
	 * @return array Class tokens.
	 */
	public function getClassList(): array {
		return $this->attributes['class'] ?? array();
	}

	/**
	 * Sets or merges one attribute.
	 *
	 * @param string $name  Attribute name.
	 * @param mixed  $value Attribute value.
	 * @return self Current instance.
	 */
	public function set( string $name, $value ): self {
		$attribute_name = trim( $name );

		if ( ! self::isSafeAttributeName( $attribute_name ) ) {
			return $this;
		}

		if ( 'class' === $attribute_name ) {
			$class_string = is_string( $value ) ? self::parseClassAttributeString( $value ) : null;
			// Support legacy helper calls that pass class="foo bar" while storing
			// classes internally as tokens for safe merging and deduplication.
			$this->addClass( null !== $class_string ? $class_string : $value );
			return $this;
		}

		$normalized_value = self::valueToAttributeParts( $value );

		if ( null === $normalized_value ) {
			return $this;
		}

		$this->attributes[ $attribute_name ] = $normalized_value;

		return $this;
	}

	/**
	 * Merges an array, traversable value, or another AttributeBag.
	 *
	 * @param mixed $value Attribute source.
	 * @return self Current instance.
	 */
	public function merge( $value ): self {
		if ( empty( $value ) ) {
			return $this;
		}

		if ( $value instanceof self ) {
			$value = $value->toArray();
		}

		if ( $value instanceof \Traversable ) {
			$value = iterator_to_array( $value );
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return $this;
		}

		foreach ( $value as $name => $attribute_value ) {
			if ( '_keys' === $name || ! is_string( $name ) ) {
				continue;
			}

			$this->set( $name, $attribute_value );
		}

		return $this;
	}

	/**
	 * Converts attributes to a plain array.
	 *
	 * @return array<string, mixed> Attribute map.
	 */
	public function toArray(): array {
		return $this->attributes;
	}

	/**
	 * Core-compatible alias.
	 *
	 * @return array<string, mixed> Attribute map.
	 */
	public function toObject(): array {
		return $this->toArray();
	}

	/**
	 * Serializes attributes for Twig output.
	 *
	 * @return string Safe serialized attributes.
	 */
	public function toString(): string {
		$output = array();

		foreach ( $this->attributes as $name => $value ) {
			if ( 'class' === $name && is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}

				$output[] = sprintf( 'class="%s"', self::escapeAttributeValue( implode( ' ', $value ) ) );
				continue;
			}

			if ( true === $value ) {
				// Boolean HTML attributes such as "disabled" should render without a
				// value. False/null were already filtered during normalization.
				$output[] = $name;
				continue;
			}

			if ( is_array( $value ) ) {
				$value = implode( ' ', self::flattenList( $value ) );
			}

			if ( is_scalar( $value ) ) {
				$output[] = sprintf( '%s="%s"', $name, self::escapeAttributeValue( (string) $value ) );
			}
		}

		return implode( ' ', $output );
	}

	/**
	 * Serializes attributes for string contexts.
	 *
	 * @return string Safe serialized attributes.
	 */
	public function __toString(): string {
		return $this->toString();
	}

	/**
	 * Converts scalar, array, or AttributeBag values into class tokens.
	 *
	 * @param mixed $value Class value.
	 * @return array Class tokens.
	 */
	public static function classTokensFromValue( $value ): array {
		if ( $value instanceof self ) {
			return $value->getClassList();
		}

		$tokens = array();

		foreach ( self::flattenList( $value ) as $item ) {
			if ( $item instanceof self ) {
				$tokens = array_merge( $tokens, $item->getClassList() );
				continue;
			}

			foreach ( preg_split( '/\s+/', (string) $item ) as $candidate ) {
				$token = self::cleanClassToken( $candidate );

				if ( '' !== $token ) {
					$tokens[] = $token;
				}
			}
		}

		return self::uniqueList( $tokens );
	}

	/**
	 * Converts a value into serializable non-class attribute parts.
	 *
	 * @param mixed $value Attribute value.
	 * @return mixed Normalized value or null when it should not render.
	 */
	private static function valueToAttributeParts( $value ) {
		if ( $value instanceof self ) {
			return $value->toArray();
		}

		if ( null === $value || false === $value ) {
			return null;
		}

		if ( is_array( $value ) ) {
			return array_map(
				static function ( $item ): string {
					return (string) $item;
				},
				self::flattenList( $value )
			);
		}

		if ( true === $value ) {
			return true;
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( is_object( $value ) && method_exists( $value, '__toString' ) ) {
			return (string) $value;
		}

		return null;
	}

	/**
	 * Flattens scalar and nested array values.
	 *
	 * @param mixed $value Value to flatten.
	 * @return array Flattened list.
	 */
	private static function flattenList( $value ): array {
		if ( null === $value || false === $value ) {
			return array();
		}

		if ( ! is_array( $value ) ) {
			return array( $value );
		}

		$items = array();

		foreach ( $value as $item ) {
			$items = array_merge( $items, self::flattenList( $item ) );
		}

		return $items;
	}

	/**
	 * Removes duplicate values while preserving first-seen order.
	 *
	 * @param array $values Values to deduplicate.
	 * @return array Unique values.
	 */
	private static function uniqueList( array $values ): array {
		$unique = array();
		$seen   = array();

		foreach ( $values as $value ) {
			$key = (string) $value;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $value;
		}

		return $unique;
	}

	/**
	 * Cleans one value into a CSS class token.
	 *
	 * @param mixed $value Candidate class token.
	 * @return string Clean class token.
	 */
	private static function cleanClassToken( $value ): string {
		$raw = trim( (string) $value );

		if ( '' === $raw ) {
			return '';
		}

		$cleaned = preg_replace( '/[^_a-zA-Z0-9-]+/', '-', $raw );
		$cleaned = trim( (string) $cleaned, '-' );
		$cleaned = preg_replace( '/^([0-9])/', '_$1', $cleaned );

		return (string) $cleaned;
	}

	/**
	 * Extracts a legacy class="..." string.
	 *
	 * @param string $value Candidate class attribute.
	 * @return string|null Class value when the string is class-only.
	 */
	private static function parseClassAttributeString( string $value ): ?string {
		if ( preg_match( '/^class=(["\'])(.*?)\1$/', $value, $matches ) ) {
			return $matches[2];
		}

		return null;
	}

	/**
	 * Checks whether an attribute name can be serialized safely.
	 *
	 * @param string $name Attribute name.
	 * @return bool TRUE when safe.
	 */
	private static function isSafeAttributeName( string $name ): bool {
		return 0 !== stripos( $name, 'on' ) && 1 === preg_match( '/^[A-Za-z_:][A-Za-z0-9:_.-]*$/', $name );
	}

	/**
	 * Escapes an attribute value for double-quoted HTML output.
	 *
	 * @param string $value Attribute value.
	 * @return string Escaped value.
	 */
	private static function escapeAttributeValue( string $value ): string {
		return strtr(
			$value,
			array(
				'&' => '&amp;',
				'"' => '&quot;',
				'<' => '&lt;',
				'>' => '&gt;',
			)
		);
	}
}
