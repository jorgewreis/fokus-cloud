#!/usr/bin/env python3
"""Experimental internal OIDC-to-environment evidence flow, not activation.

State selection and API token delivery must be fixed by trusted bootstrap. No
request-provided repository, job identity or API implementation is accepted.
"""
import time
import environment_evidence
import github_oidc
import oidc_challenge_store as store


def assess(directory, audience, oidc_token, read_token, action, candidate, artifact):
    consumed = store.consume(directory, audience, oidc_token, action, candidate, artifact)
    identity = consumed['identity']
    if type(identity) is not github_oidc.VerifiedIdentity:
        raise ValueError('verified_identity_required')
    with store.locked(directory) as (fd, checkpoint):
        settings, digest = store.policy(directory, fd)
        environment, env_digest = store.environment_policy(directory, fd)
        if digest != consumed['policy_digest'] or env_digest != consumed['environment_digest']:
            raise ValueError('authorization_changed')
        policy = {'repository':settings['repository'],'repository_id':settings['repository_id'],**environment}
        if identity.sha != settings['workflow_sha']:
            raise ValueError('identity_sha_mismatch')
        checkpoint()
    api = environment_evidence.GitHubRead(settings['repository'], read_token)
    result = environment_evidence.check(identity, policy, api)
    with store.locked(directory) as (fd, checkpoint):
        current_settings, current_digest = store.policy(directory, fd)
        current_environment, current_env_digest = store.environment_policy(directory, fd)
        if current_digest != digest or current_env_digest != env_digest:
            raise ValueError('authorization_changed')
        now = store.trusted_time(directory, fd)
        github_oidc.validate_window(identity.issued,identity.not_before,identity.expires,now)
        checkpoint()
    if result.get('state') != 'evidence_consistent':
        raise ValueError('environment_evidence_failed')
    # This proof cannot authorize a switch: activation locking, independent
    # artifact binding and current environment approval remain unproven.
    return {'state':'evidence_consistent_not_authorized','run_id':identity.run_id,
            'check_run_id':identity.check_run_id,'candidate':candidate,'artifact':artifact,
            '_identity':identity,'_policy_digest':digest,'_environment_digest':env_digest}


def recheck(directory, result):
    """Recheck protected policy and the signed window; does not authorize."""
    identity=result.get('_identity')
    if type(identity) is not github_oidc.VerifiedIdentity:
        raise ValueError('verified_identity_required')
    with store.locked(directory) as (fd, checkpoint):
        settings,digest=store.policy(directory,fd)
        _,env_digest=store.environment_policy(directory,fd)
        if digest!=result.get('_policy_digest') or env_digest!=result.get('_environment_digest'):
            raise ValueError('authorization_changed')
        if identity.sha!=settings['workflow_sha'] or identity.repository!=settings['repository'] or identity.repository_id!=settings['repository_id']:
            raise ValueError('identity_policy_mismatch')
        now=store.trusted_time(directory,fd)
        github_oidc.validate_window(identity.issued,identity.not_before,identity.expires,now)
        checkpoint()
