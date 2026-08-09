<?php
namespace Quiote\Http;

use Psr\Http\Message\UriInterface;

/**
 * A minimal PSR-7 `UriInterface` built by handing a URI string to `parse_url()`, so the
 * framework can supply a URI without depending on a third-party PSR-7 implementation.
 *
 * {@see \Quiote\Request\WebRequest} falls back to one of these (`http://localhost/`) when it
 * is constructed without a URI, which is the usual case in tests and in dispatches assembled
 * by hand rather than from an incoming HTTP request.
 *
 * Deliberately thin: components are stored exactly as parsed or as supplied to the `with*()`
 * methods. There is no percent-encoding, no case normalisation of scheme or host, no
 * validation of the values passed in, and no default-port handling — a port equal to the
 * scheme's default is still reported by {@see getPort()} and still appears in
 * {@see getAuthority()}. Every `with*()` method returns a clone.
 */
class SimpleUri implements UriInterface
{
    private string $scheme='';
    private string $user='';
    private string $pass='';
    private string $host='';
    private ?int $port=null;
    private string $path='';
    private string $query='';
    private string $fragment='';

    public function __construct(string $uri)
    {
        $parts = parse_url($uri) ?: [];
        foreach($parts as $k=>$v) { $this->$k = $v; }
    }

    public function __toString(): string { $auth = $this->user? $this->user.($this->pass?':'.$this->pass:'').'@':''; $port = $this->port? ':' . $this->port:''; $q=$this->query? '?'.$this->query:''; $f=$this->fragment? '#'.$this->fragment:''; return ($this->scheme? $this->scheme.'://':'').$auth.$this->host.$port.$this->path.$q.$f; }
    /** Returns the scheme component, or an empty string when the parsed URI had none. */
    public function getScheme(): string { return $this->scheme; }
    /** Returns `[user-info@]host[:port]`, omitting each part that is empty or unset. */
    public function getAuthority(): string { $auth = $this->user? $this->user.($this->pass?':'.$this->pass:'').'@':''; $port = $this->port? ':' . $this->port:''; return $auth.$this->host.$port; }
    /** Returns `user[:password]`, or an empty string when no user was present in the URI. */
    public function getUserInfo(): string { return $this->user.($this->pass?':'.$this->pass:''); }
    /** Returns the host component, or an empty string when the parsed URI had none. */
    public function getHost(): string { return $this->host; }
    /** Returns the port, or null when the URI did not state one; no default-port normalisation is applied. */
    public function getPort(): ?int { return $this->port; }
    /** Returns the path component, or an empty string when the parsed URI had none. */
    public function getPath(): string { return $this->path; }
    /** Returns the query string without its leading `?`, or an empty string when there is none. */
    public function getQuery(): string { return $this->query; }
    /** Returns the fragment without its leading `#`, or an empty string when there is none. */
    public function getFragment(): string { return $this->fragment; }

    /** @param array<string, mixed> $changes */
    private function cloneWith(array $changes): static { $c=clone $this; foreach($changes as $k=>$v) $c->$k=$v; return $c; }
    /** Returns a clone carrying the given scheme; the value is stored as supplied, without case or syntax normalisation. */
    public function withScheme($scheme): static { return $this->cloneWith(['scheme'=>$scheme]); }
    /** Returns a clone carrying the given user and password; a null password clears the stored password. */
    public function withUserInfo($user, $password = null): static { return $this->cloneWith(['user'=>$user,'pass'=>$password??'']); }
    /** Returns a clone carrying the given host, stored verbatim. */
    public function withHost($host): static { return $this->cloneWith(['host'=>$host]); }
    /** Returns a clone carrying the given port; null removes the port from the authority. */
    public function withPort($port): static { return $this->cloneWith(['port'=>$port]); }
    /** Returns a clone carrying the given path, stored verbatim without encoding. */
    public function withPath($path): static { return $this->cloneWith(['path'=>$path]); }
    /** Returns a clone carrying the given query, with any leading `?` stripped. */
    public function withQuery($query): static { return $this->cloneWith(['query'=>ltrim($query,'?')]); }
    /** Returns a clone carrying the given fragment, with any leading `#` stripped. */
    public function withFragment($fragment): static { return $this->cloneWith(['fragment'=>ltrim($fragment,'#')]); }
}
