<?php
/**
 * Raised when the model file can't be used. Callers fail open: the message is delivered (F12).
 *
 * @package LocalAIAntiSpam
 */

namespace LocalAIAntiSpam\Inference;

/**
 * Model loading or scoring error.
 */
final class ModelException extends \RuntimeException {
}
