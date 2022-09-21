# Type-safe database connector for PHP

This library provides a type-safe database connector for PHP. 

Inspired by the HDBC package in Haskell, this library provides a series of classes and three helper functions to help make your database interaction more deterministic and testable.

The connector works with any database driver compatible with the in-built PDO abstraction layer. That means you can use it with your SQLite3, MySQL (and MariaDB), and PostgreSQL databases with ease. 

The library requires PHP 8.1 as a minimum along with whichever PDO driver you need for your database. Once you have your `PDO` object instantiated, you're good to go!

## Usage

The test files inside `tests/` provide a range of example usages for you to peruse, but here is some documentation on how to use the library.

### \TypeDb\to_sql

This helper takes a value of type `string|float|int|null` as its sole argument and returns an object of type `\TypeDb\SqlValue\SqlValue` depending on which type of value you supply.

There is a class implementing `\TypeDb\SqlValue\SqlValue` for each of the four argument types that you can pass into the helper.

You can grab the underlying value from each class by accessing the `public readonly` property of `$value`.

```php
<?php

declare(strict_types=1);

// 1. Return an object of type \TypeDb\SqlValue\SqlString
$string = \TypeDb\to_sql("foo bar");

// Grab the underlying string:
$value = $string->value;

// 2. Return an object of type \TypeDb\SqlValue\SqlFloat
$float = \TypeDb\to_sql(1.0);

// Grab the underlying float:
$value = $float->value;

// 3. Return an object of type \TypeDb\SqlValue\SqlInteger
$integer = \TypeDb\to_sql(1);

// Grab the underlying integer:
$value = $integer->value;

// 4. Return an object of type \TypeDb\SqlValue\SqlNull
$null = \TypeDb\to_sql(null);

// \TypeDb\SqlValue\SqlNull is the only one without a $value property!
```

If you know the types in advance and don't need to use the helper, that's fine too. Simply instantiate each of the classes as follows:

```php
<?php

declare(strict_types=1);

$string = new \TypeDb\SqlValue\SqlString("foo bar");

$float = new \TypeDb\SqlValue\SqlFloat(1.0);

$integer = new \TypeDb\SqlValue\SqlInteger(1);

$null = new \TypeDb\SqlValue\SqlNull();
```

### \TypeDb\from_sql

This helper takes an object of type `\TypeDb\SqlValue\SqlValue` and returns its underlying value. You can think of it as the inverse mapping of `\TypeDb\to_sql`.

```php
<?php

declare(strict_types=1);

$string = \TypeDb\from_sql(new \TypeDb\SqlValue\SqlString("foo bar"));

$float = \TypeDb\from_sql(new \TypeDb\SqlValue\SqlFloat(1.0));

$integer = \TypeDb\from_sql(new \TypeDb\SqlValue\SqlInteger(1));

$null = \TypeDb\from_sql(new \TypeDb\SqlValue\SqlNull);
```

## Roadmap

phase 1:

- [x] connection
- [x] SqlValue interface
- [x] toSql function
- [x] unit tests
- [x] phpstan level 9
- [x] phpa

phase 2:

- [x] set up GH workflow
- [x] test write queries
- [x] documentation

phase 3: 

- [ ] transactions
- [ ] additional class-based usage
- [ ] date types
- [ ] binary type
