<?php
/**
 * Copyright (c) 2011 Ryan Gantt
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
 
class Stream implements Iterator {
	private $tailPromise;
	private $headValue;
	private $pointer;
	private $pointerIndex;

	public function __construct( $head = null, $tailPromise = null ) {
		if( $tailPromise === null ) {
			$tailPromise = function() {
				return new Stream();
			};
		}
		$this->headValue = $head;
		$this->tailPromise = $tailPromise;
	}

	/* iterator methods */
	public function rewind() {
		$this->pointer = $this;
		$this->pointerIndex = 0;
	}

	public function current() {
		return $this->pointer->head();
	}

	public function key() {
		return $this->pointerIndex;
	}

	public function next() {
		$this->pointer = $this->pointer->tail();
		++$this->pointerIndex;
	}

	public function valid() {
		return !$this->pointer->blank();
	}
	/* end of iterator methods */
	
	public function __invoke( $n ) {
		return $this->item( $n );
	}
	
	public function blank() {
		return ( $this->headValue === null );
	}
	
	public function head() {
		if( $this->blank() ) throw new Exception('Cannot get the head of the empty stream.');
		return $this->headValue;
	}
	
	public function tail() {
		if( $this->blank() ) throw new Exception('Cannot get the tail of the empty stream.');
		// TODO: memoize here
		$tp = $this->tailPromise;
		return $tp();
	}
	
	public function item( $n ) {
		if( $this->blank() ) throw new Exception('Cannot use item() on an empty stream.');
		$m = $n;
		$s = $this;
		while( $m != 0 ) {
			$m -= 1;
			try {
				$s = $s->tail();
			} catch( Exception $e ) {
				throw new Exception("Index {$n} does not exist in the stream");
			}
		}
		try {
			return $s->head();
		} catch( Exception $e ) {
			throw new Exception("Index {$n} does not exist in the stream.");
		}
	}
	
	public function length() {
		$s = $this;
		$len = 0;
		while( !$s->blank() ) {
			$len += 1;
			$s = $s->tail();
		}
		return $len;
	}
	
	public function add( $s ) {
		return $this->zip( function( $x, $y ) {
			return $x + $y;
		}, $s );
	}
	
	public function zip( $f, $s ) {
		if( $this->blank() ) return $s;
		if( $s->blank() ) return $this;
		$self = $this;
		return new Stream( $f( $s->head(), $this->head() ), function() use ( $self, $f, $s ) {
			return $self->tail()->zip( $f, $s->tail() );
		});
	}
	
	public function map( $f ) {
		if( $this->blank() ) return $this;
		$self = $this;
		return new Stream( $f( $this->head() ), function() use ( $self, $f ) {
			return $self->tail()->map( $f );
		});
	}
	
	public function reduce( $aggregator, $initial ) {
		if( $this->blank() ) return $initial;
		return $this->tail()->reduce( $aggregator, $aggregator( $initial, $this->head() ) );
	}
	
	public function sum() {
		return $this->reduce( function( $a, $b ) {
			return $a + $b;
		}, 0 );
	}
	
	public function walk( $f ) {
		$this->map( function( $x ) use ( $f ) {
			$f( $x );
			return $x;
		})->force();
	}
	
	public function force() {
		$stream = $this;
		while( !$stream->blank() ) {
			$stream = $stream->tail();
		}
	}
	
	public function scale( $factor ) {
		return $this->map( function( $x ) use ( $factor ) {
			return $factor * $x;
		});
	}
	
	public function filter( $f ) {
		if( $this->blank() ) return $this;
		$h = $this->head();
		$t = $this->tail();
		if( $f( $h ) ) {
			return new Stream( $h, function() use ( $t, $f ) {
				return $t->filter( $f );
			});
		}
		return $t->filter( $f );
	}
	
	public function take( $howmany ) {
		if( $this->blank() ) return $this;
		if( $howmany == 0 ) {
			return new Stream();
		}
		$self = $this;
		return new Stream(
			$this->head(),
			function() use( $self, $howmany ) {
				return $self->tail()->take( $howmany - 1 );
			}
		);
	}
	
	public function drop( $n ) {
		$self = $this;
		while( $n-- > 0 ) {
			if( $self->blank() ) return new Stream();
			$self = $self->tail();
		}
		return new Stream( $self->headValue, $self->tailPromise );
	}
	
	public function member( $x ) {
		$self = $this;
		while( !$self->blank() ) {
			if( $self->head() == $x ) {
				return true;
			}
			$self = $self->tail();
		}
		return false;
	}
	
	public function __toString() {
		return '[head: ' . $this->head() . '; tail: ' . $this->tail() . ']';
	}
	
	/**
	 * accepts a second parameter, $f, that is a callback
	 * to be applied during the array walk
	 */
	public function out( $n = null, $f = null ) {
		$target = null;
		if ( $n !== null ) {
			$target = $this->take( $n );
		} else {
			// requires finite stream
			$target = $this;
		}
		$target->walk( function( $x ) use ( $f ) {
			if( is_callable( $f ) ) {
				$f( $x );
			} else {
				echo $x."\n";
			}
		});
	}
	
	public static function make() {
		$args = func_get_args();
		if( count( $args ) == 0 ) {
			return new Stream();
		}
		$rest = array_slice( $args, 1 );
		return new Stream( $args[0], function() use ( $rest ) {
			return forward_static_call_array( array( 'Stream', 'make' ), $rest );
		});
	}
	
	public static function range( $low = null, $high = null ) {
		if( $low === null ) {
			$low = 1;
		}
		if( $low == $high ) {
			return Stream::make( $low );
		}
		return new Stream( $low, function() use ( $low, $high ) {
			return Stream::range( $low + 1, $high );
		});
	}
}
