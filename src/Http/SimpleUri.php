<?php
namespace Agavi\Http;

use Psr\Http\Message\UriInterface;

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
    public function getScheme(): string { return $this->scheme; }
    public function getAuthority(): string { $auth = $this->user? $this->user.($this->pass?':'.$this->pass:'').'@':''; $port = $this->port? ':' . $this->port:''; return $auth.$this->host.$port; }
    public function getUserInfo(): string { return $this->user.($this->pass?':'.$this->pass:''); }
    public function getHost(): string { return $this->host; }
    public function getPort(): ?int { return $this->port; }
    public function getPath(): string { return $this->path; }
    public function getQuery(): string { return $this->query; }
    public function getFragment(): string { return $this->fragment; }

    private function cloneWith(array $changes): static { $c=clone $this; foreach($changes as $k=>$v) $c->$k=$v; return $c; }
    public function withScheme($scheme): static { return $this->cloneWith(['scheme'=>$scheme]); }
    public function withUserInfo($user, $password = null): static { return $this->cloneWith(['user'=>$user,'pass'=>$password??'']); }
    public function withHost($host): static { return $this->cloneWith(['host'=>$host]); }
    public function withPort($port): static { return $this->cloneWith(['port'=>$port]); }
    public function withPath($path): static { return $this->cloneWith(['path'=>$path]); }
    public function withQuery($query): static { return $this->cloneWith(['query'=>ltrim($query,'?')]); }
    public function withFragment($fragment): static { return $this->cloneWith(['fragment'=>ltrim($fragment,'#')]); }
}
