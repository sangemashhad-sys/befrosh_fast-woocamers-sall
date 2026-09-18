#!/usr/bin/env node
/**
 * PHP syntax gate for environments without a PHP binary.
 *
 * Parses every PHP file with the `php-parser` npm package and reports files
 * that fail to parse, plus a legacy check for the two bootstrap files that
 * must stay parseable by PHP 5.6.
 *
 * Usage:
 *   node tools/php-syntax-check.js            # check the plugin
 *   node tools/php-syntax-check.js --json     # machine readable
 *
 * Exits non-zero when anything fails, so it can be wired into CI.
 *
 * Limitation: php-parser accepts a superset of PHP, so this catches syntax
 * errors and accidental post-5.6 syntax in the bootstrap files, but it does not
 * police the 7.4 floor for the rest of the plugin. `composer lint` (WPCS +
 * PHPCompatibilityWP) remains the authority on that and needs a PHP binary.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

let parser;

try {
	parser = require( 'php-parser' );
} catch ( error ) {
	console.error( 'php-parser is not installed. Run: npm install php-parser@3' );
	process.exit( 2 );
}

const ROOT = path.resolve( __dirname, '..' );
const PLUGIN = path.join( ROOT, 'fast-woo-sell' );

const SKIP_DIRS = new Set( [ 'node_modules', 'vendor', '.git', 'tests' ] );

/**
 * Files that run before the PHP version gate and therefore must stay parseable
 * by PHP 5.6. Anything modern in them turns a friendly notice into a white
 * screen, so they get an extra syntax scan.
 *
 * @type {string[]}
 */
const LEGACY_FILES = [ 'fast-woo-sell.php', 'includes/Core/Requirements.php' ];

/**
 * Syntax PHP 5.6 does not understand.
 *
 * Each entry is a name plus a matcher. Comment lines are stripped before the
 * match so a doc block may still mention the constructs it forbids.
 *
 * @type {Array<{name: string, pattern: RegExp}>}
 */
const LEGACY_FORBIDDEN = [
	{ name: 'scalar type hint or return type', pattern: /function\s+\w+\s*\([^)]*(?::\s*(?:int|string|bool|float|array)\b(?![(\w])|(?<![\w$\]])[\s(,](?:int|string|bool|float)\s+\$\w+)/ },
	{ name: 'nullable type', pattern: /\?\s*(?:int|string|bool|float|array|self)\s*[\$,)]/ },
	{ name: 'return type', pattern: /\)\s*:\s*(?:int|string|bool|float|void|array|self|iterable|object)\s*[{;]/ },
	{ name: 'typed property', pattern: /(?:public|protected|private)\s+(?:static\s+)?(?:int|string|bool|float|array|iterable|object)\s+\$/ },
	{ name: 'null coalescing operator', pattern: /\?\?/ },
	{ name: 'spaceship operator', pattern: /<==>|<=>/ },
	{ name: 'arrow function', pattern: /\bfn\s*\(/ },
	{ name: 'spread in call', pattern: /\(\s*\.\.\.\$/ },
	{ name: 'match expression', pattern: /\bmatch\s*\(/ },
	{ name: 'enum declaration', pattern: /\benum\s+\w+\s*[:{]/ },
	{ name: 'readonly modifier', pattern: /\breadonly\s+(?:public|protected|private|class|\$)/ },
	{ name: 'constructor property promotion', pattern: /function\s+__construct\s*\([^)]*(?:public|protected|private)\s+(?:readonly\s+)?(?:\??\w+\s+)?\$/ },
	{ name: 'named argument', pattern: /\(\s*\w+\s*:\s*(?!:)[^)]*[,)]/ },
];

/**
 * Strip comments and strings so a matcher never fires on prose.
 *
 * @param {string} source PHP source.
 * @return {string} Source with comments and string bodies blanked out.
 */
function blankCommentsAndStrings( source ) {
	let out = '';
	let i = 0;
	let state = 'code';
	let quote = '';

	while ( i < source.length ) {
		const char = source[ i ];
		const next = source[ i + 1 ];

		if ( 'code' === state ) {
			if ( '/' === char && '*' === next ) {
				state = 'block-comment';
				out += '  ';
				i += 2;
				continue;
			}

			if ( '/' === char && '/' === next ) {
				state = 'line-comment';
				out += '  ';
				i += 2;
				continue;
			}

			if ( '#' === char ) {
				state = 'line-comment';
				out += ' ';
				i += 1;
				continue;
			}

			if ( "'" === char || '"' === char ) {
				state = 'string';
				quote = char;
				out += char;
				i += 1;
				continue;
			}

			out += char;
			i += 1;
			continue;
		}

		if ( 'block-comment' === state ) {
			if ( '*' === char && '/' === next ) {
				state = 'code';
				out += '  ';
				i += 2;
				continue;
			}

			out += '\n' === char ? '\n' : ' ';
			i += 1;
			continue;
		}

		if ( 'line-comment' === state ) {
			if ( '\n' === char ) {
				state = 'code';
				out += '\n';
				i += 1;
				continue;
			}

			out += ' ';
			i += 1;
			continue;
		}

		// Inside a single quoted string the only escape is \\ and \'.
		if ( '\\' === char ) {
			out += '  ';
			i += 2;
			continue;
		}

		if ( char === quote ) {
			state = 'code';
			out += char;
			i += 1;
			continue;
		}

		out += '\n' === char ? '\n' : ' ';
		i += 1;
	}

	return out;
}

/**
 * Every PHP file under a directory.
 *
 * @param {string} dir Directory to walk.
 * @return {string[]} Absolute file paths.
 */
function phpFiles( dir ) {
	const found = [];

	if ( ! fs.existsSync( dir ) ) {
		return found;
	}

	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		const full = path.join( dir, entry.name );

		if ( entry.isDirectory() ) {
			if ( SKIP_DIRS.has( entry.name ) ) {
				continue;
			}

			found.push( ...phpFiles( full ) );
			continue;
		}

		if ( entry.isFile() && '.php' === path.extname( entry.name ) ) {
			found.push( full );
		}
	}

	return found;
}

/**
 * Parse one file.
 *
 * @param {string} file Absolute path.
 * @return {{file: string, ok: boolean, message: string}} Result.
 */
function checkFile( file ) {
	const engine = new parser.Engine( {
		parser: { extractDoc: false, suppressErrors: false },
		ast: { withPositions: true },
	} );

	const relative = path.relative( ROOT, file );
	const source = fs.readFileSync( file, 'utf8' );

	try {
		engine.parseCode( source, relative );

		return { file: relative, ok: true, message: '' };
	} catch ( error ) {
		const line = error.loc && error.loc.start ? error.loc.start.line : '?';

		return {
			file: relative,
			ok: false,
			message: `${error.message} (line ${line})`,
		};
	}
}

/**
 * Forbid post-5.6 syntax in the pre-gate files.
 *
 * @param {string} file Absolute path.
 * @return {string[]} One message per violation.
 */
function checkLegacy( file ) {
	const source = blankCommentsAndStrings( fs.readFileSync( file, 'utf8' ) );
	const relative = path.relative( ROOT, file );
	const problems = [];

	for ( const rule of LEGACY_FORBIDDEN ) {
		const lines = source.split( '\n' );

		lines.forEach( ( line, index ) => {
			if ( rule.pattern.test( line ) ) {
				problems.push( `${relative}:${index + 1} uses ${rule.name}: ${line.trim()}` );
			}
		} );
	}

	return problems;
}

function main() {
	const asJson = process.argv.includes( '--json' );
	const files = phpFiles( PLUGIN );
	const results = files.map( checkFile );
	const failures = results.filter( ( result ) => ! result.ok );

	const legacy = [];
	for ( const relative of LEGACY_FILES ) {
		const file = path.join( PLUGIN, relative );

		if ( fs.existsSync( file ) ) {
			legacy.push( ...checkLegacy( file ) );
		}
	}

	if ( asJson ) {
		console.log( JSON.stringify( { files: results.length, failures, legacy }, null, 2 ) );
	} else {
		console.log( `Checked ${results.length} PHP files.` );

		for ( const failure of failures ) {
			console.log( `  ✗ ${failure.file}: ${failure.message}` );
		}

		for ( const problem of legacy ) {
			console.log( `  ✗ PHP 5.6 violation — ${problem}` );
		}

		if ( 0 === failures.length && 0 === legacy.length ) {
			console.log( '  ✓ every file parses, and the pre-gate files stay 5.6 safe.' );
		}
	}

	process.exit( failures.length || legacy.length ? 1 : 0 );
}

main();
