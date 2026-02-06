# Tests SAML Group Gating

## Vue d'ensemble

Les tests unitaires pour la fonctionnalité de verrou SAML par groupe sont maintenant en place. Ils couvrent :

1. **SamlUserContextTest** : 9 tests unitaires pour valider la logique d'extraction et de filtrage des groupes/rôles.
2. **UserMapperTest** (étendu) : 4 tests incluant les nouveaux tests pour le groupe requis et le fallback.

## Fichiers de test

```
app/bundles/UserBundle/Tests/Security/SAML/User/
├── SamlUserContextTest.php       (9 tests)
└── UserMapperTest.php             (4 tests, dont 2 nouveaux)
```

## Résultats

```bash
✅ SamlUserContextTest: 9 tests (100%)
✅ UserMapperTest: 4 tests (100%)
```

## Tests inclus

### SamlUserContextTest

1. **testHasRequiredGroupReturnsTrueWhenGroupNotConfigured** : Quand `MAUTIC_SAML_REQUIRED_GROUP_ID` est vide, l'auth est acceptée.
2. **testHasRequiredGroupReturnsFalseWhenGroupMissing** : Quand le groupe requis est configuré mais absent dans le token, l'auth est refusée.
3. **testHasRequiredGroupReturnsTrueWhenGroupPresent** : Quand le groupe requis est présent, l'auth est acceptée.
4. **testHasRequiredGroupHandlesSlashesInGroupId** : Les slashes sont normalisés correctement.
5. **testGetMatchedRoleExtractsRoleCorrectly** : Le rôle est extrait du format `/org_id/roles/role_name`.
6. **testGetMatchedRoleReturnsNullWhenGroupMissing** : Pas de rôle si le groupe n'est pas présent.
7. **testGetMatchedRoleReturnsNullWhenRoleNotMatching** : Pas de rôle si le rôle ne correspond pas au groupe.
8. **testGetMatchedRoleIgnoresInvalidFormats** : Les formats invalides sont ignorés.
9. **testGetMatchedRoleReturnsFirstRoleWhenNoGroupRequired** : Sans groupe requis, le premier rôle est retourné.

### UserMapperTest

1. **testUserEntityIsPopulatedFromAssertions** (existant) : Les attributs SAML sont mappés correctement.
2. **testUsernameIsReturned** (existant) : Le username est retourné.
3. **testGetUsernameThrowsExceptionWhenRequiredGroupMissing** (nouveau) : Exception levée si groupe manquant.
4. **testGetUsernameAcceptsWhenNoGroupRequired** (nouveau) : Pas d'exception si aucun groupe requis.

## Exécuter les tests

```bash
# Tous les tests SAML
./bin/phpunit app/bundles/UserBundle/Tests/Security/SAML/User/

# Test spécifique
./bin/phpunit app/bundles/UserBundle/Tests/Security/SAML/User/SamlUserContextTest.php
./bin/phpunit app/bundles/UserBundle/Tests/Security/SAML/User/UserMapperTest.php

# Avec coverage (optionnel)
./bin/phpunit --coverage-html build/coverage app/bundles/UserBundle/Tests/Security/SAML/User/
```

## Ce qui est couvert

✅ Extraction des groupes/rôles du token SAML  
✅ Validation du groupe requis (optionnel)  
✅ Matching du rôle par org_id  
✅ Normalisation des IDs (slashes)  
✅ Fallback quand aucun groupe n'est requis  
✅ Rejection d'un user quand le groupe manque  

## Ce qui n'est PAS couvert (optionnel)

- Tests fonctionnels complets (flow authentification)
- Tests du UserCreator (mapping rôles Mautic)
- Tests d'intégration avec AuthenticationHandler
- Tests du AuthenticationHandler avec 403

Ces tests pourraient être ajoutés dans des suites fonctionnelles/d'intégration si besoin.

## Note

Les tests existants (testUserEntity..., testUsername...) restent inchangés et passent toujours. Les nouveaux tests valident spécifiquement le comportement du groupe requis.
