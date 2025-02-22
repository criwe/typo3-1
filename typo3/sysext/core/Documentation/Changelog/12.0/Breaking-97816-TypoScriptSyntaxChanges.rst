.. include:: /Includes.rst.txt

.. _breaking-97816-1656350406:

============================================
Breaking: #97816 - TypoScript syntax changes
============================================

See :issue:`97816`

Description
===========

.. note::

    The new TypoScript parser is currently not or only partially active
    in the system. The full implementation should be finished until v12 LTS.
    This note should be removed later.

TYPO3 v12 comes with a new TypoScript syntax parser that is more performant,
more robust and allows better tooling in the Backend.

The new parser is more forgiving in many places, but some seldom used syntax
details have been removed, too. This documentation explains details that may
be breaking for existing instances.

Also see :ref:`the feature documentation <feature-97816-1656350667>`
for an overview of syntax improvements.

Impact
======

Using one of the constructs below stops working in v12 and needs
TypoScript adaptions.

Affected installations
======================

Instances using TypoScript as outlined below.

Migration
=========

Streamlined constants usage
---------------------------

It has never been fully documented in which context "constants" :typoscript:`{$foo}`
shall be used and which exact capabilities they have. The main TypoScript constants
documentation within the :ref:`TypoScript Reference <t3tsref:Constants>` was partially
outdated, and the :ref:`TSconfig documentation <t3tsconfig:Syntax>` claimed TSconfig
is not constants aware at all, which isn't fully the case anymore. Let's sort out
some details:

* Nesting constants is **not** possible and never has been. A construct like
  this is invalid syntax and is treated as string literal: :typoscript:`{$foo{$bar}}`

* Recursive constants were possible with the old parser but are not supported with the new
  parser anymore. This was never documented, the Backend Template module never showed them as
  resolved, only the Frontend parsed recursive constants. The simple rule is now: Never
  access a constant within another constant. Instances using a construct like the below one
  need to untie constants.

  ..  code-block:: typoscript

      constants:
      foo = fooValue
      # This does not resolve to "fooValue" but is kept as string literal "{$foo}"
      bar = {$foo}

      setup:
      # This does NOT resolve to "fooValue", but to the string literal "{$foo}"
      myValue = {$bar}

* Constants are now restricted to "assignments" and "conditions". Using a constant to
  substitute an "identifier" / "object path" is no longer allowed. This has never been
  clarified in the docs before and instances abusing constants to specify object paths
  should be seldom and need to resolve the situation with the new parser now:

  This is supported:

  ..  code-block:: typoscript

      # Simple constant usage as assignment value:
      foo = {$bar}
      # Compiling a value with string literals and constants:
      foo = I am {$bar}
      # Using a constant in a condition:
      [ myValue = {$bar} ]
      # Using constant(s) in multiline assignments:
      foo (
          I am {$bar} and {$baz}
      )

  These constructs are *not* supported:

  ..  code-block:: typoscript

      # Using a constant as object path specification
      {$bar} = myValue
      # This is an object path specification, too, and not supported:
      foo < {$bar}

* PageTsConfig *does* support constant substitution: Site constants can be used
  in PageTsconfig. This has been introduced with TYPO3 v10, see
  :ref:`feature-91080-1657827157` for details.

File includes are always top level
----------------------------------

File includes with :typoscript:`@import` and :typoscript:`<INCLUDE_TYPOSCRIPT:` within
curly braces are not relative anymore. A construct like this is invalid:

..  code-block:: typoscript

    page = PAGE
    page {
        @import 'EXT:my_extension/Configuration/TypoScript/bar.typoscript'
        20 = TEXT
        20.value = bar
    }

With :file:`EXT:my_extension/Configuration/TypoScript/bar.typoscript` having this content:

..  code-block:: typoscript

    10 = TEXT
    10.value = foo

This *no longer* leads to this TypoScript:

..  code-block:: typoscript

    page = PAGE
    page.10 = TEXT
    page.10.value = foo
    page.20 = TEXT
    page.20.value = bar

Instead, the following TypoScript will be calculated:

..  code-block:: typoscript

    page = PAGE
    10 = TEXT
    10.value = foo
    20 = TEXT
    20.value = bar

This means :typoscript:`@import` and :typoscript:`<INCLUDE_TYPOSCRIPT:` basically break
any curly braces level, resetting current scope to top level. While inclusion of files has
never been documented to be valid within braces assignments, it still worked until TYPO3 v11.
This is now disallowed and must not be used anymore.


UTF-8 BOM in TypoScript files
-----------------------------

The new TypoScript parser no longer ignores `UTF-8 BOM <https://en.wikipedia.org/wiki/Byte_order_mark>`_
in included files: Having a Byte-order-mark in TypoScript files may create undesired
results. They should be removed. UTF-8 BOM is disallowed in various other languages,
for instance JSON and PHP. The new parser follows here. Modern editors typically don't
add an UTF-8 BOM anymore.

Instances can check if they use UTF-8 BOM with a Unix shell command:

..  code-block:: bash

    # find affected files
    find . -type f -print0 | xargs -0 -n1 file {} | grep 'UTF-8 Unicode (with BOM)'
    # remove UTF-8 BOM from a single file
    sed -i '1s/^\xEF\xBB\xBF//' affectedFile.typoscript

Support for \\n and \\r\\n linebreaks only
------------------------------------------

TypoScript sources must terminate single lines with either "\\n" (Unix ending: LineFeed),
or "\\r\\n" (Windows ending: Carriage return, LineFeed). Ancient Mac, prior to Mac OS X
used "\\r" as single linebreak character. This old linebreak type is no longer detected
when parsing TypoScript and may lead to funny results, but chances are very low any
instance is affected by this.

.. index:: Backend, Frontend, TSConfig, TypoScript, NotScanned, ext:core
