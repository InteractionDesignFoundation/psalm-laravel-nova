<?php declare(strict_types=1);

/**
 * Application-side stand-ins the scenarios narrow their callbacks to. They live next to the
 * framework fakes (outside <projectFiles>) so both scenario files can share one declaration.
 */

namespace App\Models;

class Post extends \Illuminate\Database\Eloquent\Model
{
    public string $title = '';
    public bool $published = false;
}
