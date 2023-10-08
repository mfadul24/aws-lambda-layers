package main

import (
	"context"
	"sync"
	"time"
    "github.com/goccy/go-json"

	"github.com/aws/aws-lambda-go/lambda"
	"github.com/aws/aws-lambda-go/events"
	"github.com/roadrunner-server/api/v2/payload"
	"github.com/roadrunner-server/api/v2/plugins/server"
	"github.com/roadrunner-server/api/v2/pool"
	poolImp "github.com/roadrunner-server/sdk/v2/pool"
	"go.uber.org/zap"
)

type Plugin struct {
	sync.Mutex
	log     *zap.Logger
	srv     server.Server
	wrkPool pool.Pool
}

func (p *Plugin) Init(srv server.Server, log *zap.Logger) error {
	var err error
	p.srv = srv
	p.log = log
	return err
}

func (p *Plugin) Serve() chan error {
	errCh := make(chan error, 1)
	p.Lock()
	defer p.Unlock()
	var err error

	p.wrkPool, err = p.srv.NewWorkerPool(context.Background(), &poolImp.Config{NumWorkers: 4, DestroyTimeout: time.Second}, nil, nil)

	go func() {
		// register handler
		lambda.Start(p.handler())
	}()

	if err != nil {
		errCh <- err
	}
	return errCh
}

func (p *Plugin) Stop() error {
	p.Lock()
	defer p.Unlock()

	if p.wrkPool != nil {
		p.wrkPool.Destroy(context.Background())
	}
	return nil
}

func (p *Plugin) handler() func(ctx context.Context, request events.APIGatewayV2HTTPRequest) (events.APIGatewayV2HTTPResponse, error) {
	return func(ctx context.Context, request events.APIGatewayV2HTTPRequest) (events.APIGatewayV2HTTPResponse, error) {
		// execute on worker pool
		if nil == p.wrkPool {
			// or any error
			return events.APIGatewayV2HTTPResponse{Body: "", StatusCode: 500}, nil
		}

        requestJson, err := json.Marshal(request)
        if err != nil {
            return events.APIGatewayV2HTTPResponse{Body: "", StatusCode: 500}, nil
        }
        ctxJson, err := json.Marshal(ctx)
        if err != nil {
            return events.APIGatewayV2HTTPResponse{Body: "", StatusCode: 500}, nil
        }

		exec, err := p.wrkPool.Exec(&payload.Payload{
			Context: ctxJson,
			Body:    requestJson,
		})

		if err != nil {
			return events.APIGatewayV2HTTPResponse{Body: "", StatusCode: 500}, nil
		}

        var response events.APIGatewayV2HTTPResponse
        err = json.Unmarshal(exec.Body, &response)
        if err != nil {
            return events.APIGatewayV2HTTPResponse{Body: "", StatusCode: 500}, nil
        }
        return response, nil
	}
}
