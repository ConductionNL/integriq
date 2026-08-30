# openconnector-services-direct-or-usage

Chain C (code): Refactor all openconnector services and controllers to use OR's ObjectService directly instead of the transitional ObjectMapperFacade introduced in chain B. Deletes the facade and the 15 lib/Db/*Mapper.php files per ADR-001. Updates 20+ services and REST controllers. Depends on chain B landing first.
