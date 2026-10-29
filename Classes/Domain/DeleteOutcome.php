<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Domain;

enum DeleteOutcome: string
{
    /** File removed from the storage */
    case Deleted = 'deleted';
    /** Storage has a _recycler_ folder, file was moved there */
    case MovedToRecycler = 'recycler';
    /** File is referenced (or excluded) by now, nothing was done */
    case SkippedReferenced = 'referenced';
    /** No sys_file record with this uid */
    case SkippedNotFound = 'notFound';
    /** Storage refused the deletion (permissions, driver error, ...) */
    case Failed = 'failed';
}
