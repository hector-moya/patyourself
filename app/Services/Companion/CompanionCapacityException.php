<?php

namespace App\Services\Companion;

/**
 * The one economy refusal that is never a shortage.
 *
 * Thrown only by {@see CompanionEconomyException::noRoomFor()}. Every other
 * factory on the parent class means "you have not paid the price yet" —
 * this one means the opposite: the price was paid in full, and there is
 * simply nowhere left to put what it would make.
 *
 * A distinct class rather than a flag or a message check, so a caller can
 * tell the two apart with a second `catch` and never has to parse copy the
 * feature is free to reword. {@see CompanionBuildController::store()} is that
 * caller today.
 */
final class CompanionCapacityException extends CompanionEconomyException {}
