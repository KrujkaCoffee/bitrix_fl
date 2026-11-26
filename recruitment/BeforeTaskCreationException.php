<?php

namespace Kelast\Recruitment;

/** Only throw while it is certain that TaskServiceBuilder::build() was not called. */
class BeforeTaskCreationException extends \RuntimeException
{
}
