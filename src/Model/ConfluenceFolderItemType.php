<?php

declare(strict_types=1);

namespace App\Model;

enum ConfluenceFolderItemType: string
{
    case Folder = 'folder';
    case Page = 'page';
}
