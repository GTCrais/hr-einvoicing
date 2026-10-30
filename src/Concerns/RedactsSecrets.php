<?php

namespace GTCrais\HrEInvoicing\Concerns;

/**
 * Keeps an account's secrets (Moj-eRačun's password, ePoslovanje's API key) out of what a client says when a call fails.
 * An app stores that message and shows it (on the invoice, on its settings page), so whatever the intermediary or the
 * transport echoed back is left out of it: as given, as JSON writes it inside a string, and as HTML writes it; and the
 * start of a body is read with its JSON escapes first, so a secret the answer escaped is seen too.
 */
trait RedactsSecrets
{
	/**
	 * The message with every secret in it replaced by "[redacted]".
	 *
	 * @param  list<mixed>  $secrets
	 */
	protected function redact(string $message, array $secrets): string
	{
		foreach ($secrets as $secret) {
			if (!is_string($secret) || $secret === '') {
				continue;
			}

			$forms = array_unique([
				$secret,
				substr((string) json_encode($secret, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1),
				htmlspecialchars($secret, ENT_QUOTES),
			]);

			$message = str_replace(array_values(array_filter($forms, fn (string $form) => $form !== '')), '[redacted]', $message);
		}

		return $message;
	}

	/**
	 * The start of an answer's body, for a message when nothing in the answer says why: its JSON escapes read (a .NET
	 * service writes "+" and every letter outside ASCII as \uXXXX) and its HTML character references too (ASP.NET Core's
	 * pages write "+" and every letter outside ASCII as &#x2B; and the like), the secrets left out, at most 300 characters.
	 *
	 * @param  list<mixed>  $secrets
	 */
	protected function bodyExcerpt(string $body, array $secrets): string
	{
		$text = (string) preg_replace_callback(
			'/(?:\\\\(?:u[0-9a-fA-F]{4}|["\\\\\/bfnrt]))+/',
			fn (array $escapes) => is_string($read = json_decode('"' . $escapes[0] . '"')) ? $read : $escapes[0],
			$body
		);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		return mb_substr($this->redact(trim($text), $secrets), 0, 300);
	}
}
