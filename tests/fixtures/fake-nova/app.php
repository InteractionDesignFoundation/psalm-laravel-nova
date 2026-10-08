<?php declare(strict_types=1);

/**
 * Application-side stand-ins the scenarios narrow their callbacks to. They live next to the
 * framework fakes (outside <projectFiles>) so both scenario files can share one declaration.
 */

namespace App\Models;

/**
 * @property string $headline
 * @property-read int $views
 */
class Post extends \Illuminate\Database\Eloquent\Model
{
    public string $title = '';
    public bool $published = false;
}

class User extends \Illuminate\Database\Eloquent\Model {}
