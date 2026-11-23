<?php

namespace SDU;

use MediaWiki\Parser\Sanitizer;
use MediaWiki\Title\Title;

/**
 * Turns the raw value of the dependency property into the pieces SDU queries.
 *
 * A value reaches SDU in one of two forms. Read from the store it is the text
 * the editor wrote (a page name is plain, "A & B"). Read from the parse-time
 * semantic data of a save it is what {{FULLPAGENAME}} printed, and that
 * escapes & ' " ; = as character references ("A &#38; B"). A reference ends
 * in ";", the same character PageForms uses to separate several values, so the
 * value has to be split before the references are decoded, and each piece
 * decoded exactly once.
 */
class DependencyValue {

	/**
	 * Replaces the ";" that ends a character reference, so a separator split
	 * does not cut inside it.
	 */
	private const MASK = "\x1A";

	/**
	 * @param string $value
	 * @param bool $encoded whether $value still holds character references
	 * @return string The text the value stands for (one decoding pass)
	 */
	public static function decode( string $value, bool $encoded ): string {
		return $encoded ? Sanitizer::decodeCharReferences( $value ) : $value;
	}

	/**
	 * Splits a multi-value dependency value at the list separator.
	 *
	 * @param string $value
	 * @param string|null $separator PageForms list separator, if any
	 * @param bool $encoded whether $value still holds character references
	 * @return string[] decoded, trimmed, non-empty pieces
	 */
	public static function split( string $value, ?string $separator, bool $encoded ): array {
		if ( $separator === null || $separator === '' ) {
			$pieces = [ $value ];
		} else {
			if ( $encoded ) {
				$value = preg_replace_callback(
					'/&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]*);/',
					static fn ( array $m ) => str_replace( ';', self::MASK, $m[0] ),
					$value
				);
			}

			$pieces = array_map(
				static fn ( string $piece ) => str_replace( self::MASK, ';', $piece ),
				explode( $separator, $value )
			);
		}

		$result = [];

		foreach ( $pieces as $piece ) {
			$piece = trim( self::decode( $piece, $encoded ) );

			if ( $piece !== '' ) {
				$result[] = $piece;
			}
		}

		return $result;
	}

	/**
	 * @param string $text decoded text, as a person would type a page name
	 * @return Title|null
	 */
	public static function titleFromText( string $text ): ?Title {
		// Title parsing decodes character references itself; protecting the
		// "&" keeps a name that contains the text "&amp;" what it is.
		return Title::newFromText( str_replace( '&', '&#38;', $text ) );
	}

	/**
	 * Whether a decoded piece is the name of one page rather than a query
	 * condition: the page exists (or is the page being saved), and the name
	 * is not a category or concept, whose members a query would return.
	 *
	 * The dependency property is a text value and carries no type, so a
	 * value such as "A OR B" cannot be told from a list by its text alone.
	 * Existence is the only evidence available, which is why this is a
	 * heuristic and where it ends: a reference to a page that does not exist
	 * yet, and is not the page being saved, is read as a list when its name
	 * contains " OR " or " AND " as a word. It then does not update that page:
	 * with " OR " it updates the pages named by its parts, with " AND " the
	 * result is whatever the conjunction query returns. The same holds for a raw ";" (a value
	 * read from the store, or typed): {{FULLPAGENAME}} writes ";" as a
	 * character reference, which is never a separator. Names that merely
	 * contain the letters (ORDER, AORB) and the characters & ' " = are not
	 * affected. Avoid " OR ", " AND " and ";" in page names.
	 */
	public static function namesOnePage( string $text, ?Title $self ): bool {
		if ( $text === '' || strpos( $text, '[[' ) !== false || strpos( $text, '::' ) !== false ) {
			return false;
		}

		$title = self::titleFromText( $text );

		if ( $title === null || $title->inNamespaces( NS_CATEGORY, SMW_NS_CONCEPT ) ) {
			return false;
		}

		return $title->exists() || ( $self !== null && $title->equals( $self ) );
	}

	/**
	 * Builds the query condition (without the outer brackets) for a piece.
	 * The operators AND and OR count only as separate words, so a page name
	 * that merely contains the letters stays one name.
	 */
	public static function toCondition( string $text, bool $isPageName ): string {
		if ( $isPageName ) {
			return $text;
		}

		$text = preg_replace( '/\s+AND\s+/', ' ]] [[ ', $text );

		return preg_replace( '/\s+OR\s+/', ' ]] OR [[ ', $text );
	}
}
