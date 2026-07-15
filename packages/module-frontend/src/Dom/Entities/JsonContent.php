<?php
namespace Z77\Module\Frontend\Dom\Entities;

use Z77\Persistence\Attributes as Z77;

#[Z77\Driver(driver: 'file', path: '/data/contents.json')]
class Content
{
    public ?int $id = null;
    public string $title = '';
    public string $content = '';
}
